<?php

namespace Bnh\TranslatableFieldBundle\Helpers;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\Driver\AttributeReader;
use Doctrine\ORM\NoResultException;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;
use Doctrine\ORM\Query as Query;
use Gedmo\Translatable\TranslatableListener as TranslatableListener;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation as AbstractPersonalTranslation;
use Gedmo\Mapping\Annotation\TranslationEntity as TranslationEntity;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use function array_map;
use function get_class;
use function method_exists;

class TranslatableFieldManager
{
    const GEDMO_TRANSLATION_WALKER = TranslationWalker::class;

    const GEDMO_PERSONAL_TRANSLATIONS_GET = 'getTranslations';

    const GEDMO_PERSONAL_TRANSLATIONS_SET = 'addTranslation';

    const GEDMO_PERSONAL_TRANSLATIONS_FIND = 'hasTranslation';

    private EntityRepository $translationRepository;

    private AttributeReader $annotationReader;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $defaultLocale,
        private PropertyAccessorInterface $propertyAccessor
    ) {
        $this->translationRepository = $this->entityManager->getRepository(Translation::class);
        $this->annotationReader = new AttributeReader();
    }


    private function getTranslations($entity, string $fieldName): array
    {
        // 'personal' translations (separate table for each entity)
        if (method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_GET) && \is_callable(array(
                $entity,
                self::GEDMO_PERSONAL_TRANSLATIONS_GET
            ))) {
            $translations = array();
            /* @var $translation AbstractPersonalTranslation */
            foreach ($entity->{self::GEDMO_PERSONAL_TRANSLATIONS_GET}() as $translation) {
                $translations[$translation->getLocale()] = $translation->getContent();
            }

            return $translations;
        } // 'basic' translations (ext_translations table)
        else {
            return array_map(function ($element) use ($fieldName) {
                return $element[$fieldName] ?? null;
            }, $this->translationRepository->findTranslations($entity));
        }
    }

    private function getEntityInDefaultLocale($entity)
    {
        $class = get_class($entity);
        $identifierField = $this->entityManager->getClassMetadata($class)->getIdentifier()[0]; // <- none composite keys only
        $identifierValue = $this->propertyAccessor->getValue($entity, $identifierField);

        try {
            return $this->entityManager->getRepository($class)->createQueryBuilder('entity')
                ->select("entity")
                ->where("entity.$identifierField = :identifier")
                ->setParameter('identifier', $identifierValue)
                ->setMaxResults(1)
                ->getQuery()
                ->useQueryCache(false)
                ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::GEDMO_TRANSLATION_WALKER)
                ->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $this->defaultLocale)
                ->getSingleResult();
        } catch (NoResultException $exception) {
            return null;
        }
    }

    private function setFieldInDefaultLocale($entity, $field, $value): void
    {
        $class = get_class($entity);
        $identifierField = $this->entityManager->getClassMetadata($class)->getIdentifier()[0];
        $identifierValue = $this->propertyAccessor->getValue($entity, $identifierField);

        $qb = $this->entityManager->getRepository($class)->createQueryBuilder('entity');

        $qb->update()
            ->set("entity.$field", '?1')
            ->setParameter(1, $value)
            ->where("entity.$identifierField = :identifier")
            ->setParameter('identifier', $identifierValue)
            ->getQuery()
            ->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $this->defaultLocale)
            ->execute();
    }

    // SELECT
    public function getTranslatedFields($entity, string $fieldName): array
    {
        // translations
        $translations = $this->getTranslations($entity, $fieldName);

        $entity = $this->getEntityInDefaultLocale($entity);
        // translations + default locale value
        $translations[$this->defaultLocale] = $entity ? $this->propertyAccessor->getValue($entity, $fieldName) : null;

        return $translations;
    }

    private function getPersonalTranslationClassName($entity): ?string
    {
        $metadata = $this->entityManager->getClassMetadata(get_class($entity));
        return $metadata->getAssociationTargetClass('translations');
    }

    private function getEntityId($entity)
    {
        $identifierField = $this->entityManager->getClassMetadata(get_class($entity))->getIdentifier()[0];
        return $this->propertyAccessor->getValue($entity, $identifierField);
    }

    // remove record from 'ext_translation' table
    private function removeTranslation($locale, $fieldName, $class, $foreignId): Query
    {
        $qb = $this->translationRepository->createQueryBuilder('t');

        return $qb->delete()
            ->where('t.objectClass = :class')
            ->andWhere('t.field = :fieldName')
            ->andWhere('t.foreignKey = :id')
            ->andWhere('t.locale = :locale')
            ->setParameter('class', $class)
            ->setParameter('fieldName', $fieldName)
            ->setParameter('id', $foreignId)
            ->setParameter('locale', $locale)
            ->getQuery();
    }

    /**
     * @throws ReflectionException
     */
    private function removePersonalTranslation($locale, $fieldName, $class, $objectId): Query
    {
        $translationClass = null;
        $reflectionClass = new ReflectionClass($class);
        do {
            $classAttributes = $this->annotationReader->getClassAttributes($reflectionClass);
            if (isset($classAttributes[TranslationEntity::class])) {
                $translationClass = $classAttributes[TranslationEntity::class]->class;
                break;
            }
        } while ($reflectionClass = $reflectionClass->getParentClass());

        $qb = $this->entityManager->getRepository($translationClass)->createQueryBuilder('t');

        return $qb->delete()
            ->where('t.locale = :locale')
            ->andWhere('t.object = :object_id')
            ->andWhere('t.field = :field_name')
            ->setParameter('locale', $locale)
            ->setParameter('object_id', $objectId)
            ->setParameter('field_name', $fieldName)
            ->getQuery();
    }

    // UPDATE
    public function persistTranslations(FormInterface $form, $locales): void
    {
        $entity = $form->getParent()->getData();
        $fieldName = $form->getName();
        $submittedValues = $form->getData();

        $removeQueries = array();
        $personalTranslations = method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_SET) && \is_callable(array(
                $entity,
                self::GEDMO_PERSONAL_TRANSLATIONS_SET
            ));
        foreach ($locales as $locale) {
            if (array_key_exists($locale, $submittedValues)) {
                $value = $submittedValues[$locale];
                if ($value === null) {
                    // remove - default locale - external / personal
                    $entityId = $this->getEntityId($entity);
                    if ($locale === $this->defaultLocale) {
                        $this->setFieldInDefaultLocale($entity, $fieldName, null);
                    } elseif ($entityId) {
                        if ($personalTranslations && $entity->{self::GEDMO_PERSONAL_TRANSLATIONS_FIND}($locale,
                                $fieldName)) {
                            // remove - not default locale - personal
                            $removeQueries[] = $this->removePersonalTranslation($locale, $fieldName,
                                get_class($entity), $entityId);
                        } else {
                            // remove - not default locale - external
                            $removeQueries[] = $this->removeTranslation($locale, $fieldName, get_class($entity),
                                $entityId);
                        }
                    }
                    continue;
                } else {
                    if ($personalTranslations) {
                        // add - default locale - personal
                        if ($locale === $this->defaultLocale) {
                            $this->setFieldInDefaultLocale($entity, $fieldName, $value);
                        } else {
                            // add - not default locale - personal
                            $translationClassName = $this->getPersonalTranslationClassName($entity);
                            $entity->{self::GEDMO_PERSONAL_TRANSLATIONS_SET}(new $translationClassName($locale,
                                $fieldName, $value));
                        }
                    } else {
                        // add - any locale - external
                        $this->translationRepository->translate($entity, $fieldName, $locale, $value);
                    }
                }
            }
        }

        // run delete queries
        if (!empty($removeQueries)) {
            $this->entityManager->wrapInTransaction(function () use ($removeQueries) {
                foreach ($removeQueries as $deleteQuery) {
                    $deleteQuery->execute();
                }
            });
        }

        $this->entityManager->persist($entity);
    }
}
