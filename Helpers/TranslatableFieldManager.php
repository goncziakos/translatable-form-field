<?php

namespace Bnh\TranslatableFieldBundle\Helpers;

use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\Form as Form;
use Symfony\Component\PropertyAccess\PropertyAccess as PropertyAccess;
use Doctrine\ORM\Query as Query;
use Gedmo\Translatable\TranslatableListener as TranslatableListener;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation as AbstractPersonalTranslation;
use Doctrine\Common\Annotations\AnnotationReader as AnnotationReader;
use Gedmo\Mapping\Annotation\TranslationEntity as TranslationEntity;

class TranslatableFieldManager
{

    const GEDMO_TRANSLATION = 'Gedmo\\Translatable\\Entity\\Translation';
    const GEDMO_TRANSLATION_WALKER = 'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker';
    const GEDMO_PERSONAL_TRANSLATIONS_GET = 'getTranslations';
    const GEDMO_PERSONAL_TRANSLATIONS_SET = 'addTranslation';
    const GEDMO_PERSONAL_TRANSLATIONS_FIND = 'hasTranslation';

    protected $em;
    private $translationRepository;
    private $propertyAccessor;
    private $defaultLocale;
    private $annotationReader;

    public function __construct(ManagerRegistry $reg, $defaultLocale)
    {
        $this->em = $reg->getManager();
        $this->translationRepository = $this->em->getRepository(self::GEDMO_TRANSLATION);
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        $this->defaultLocale = $defaultLocale;
        $this->annotationReader = new AnnotationReader();
    }

    /* @var $translation AbstractPersonalTranslation */
    private function getTranslations($entity, string $fieldName)
    {
        // 'personal' translations (separate table for each entity)
        if (\method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_GET) && \is_callable(array(
                $entity,
                self::GEDMO_PERSONAL_TRANSLATIONS_GET
            ))) {
            $translations = array();
            foreach ($entity->{self::GEDMO_PERSONAL_TRANSLATIONS_GET}() as $translation) {
                $translations[$translation->getLocale()] = $translation->getContent();
            }

            return $translations;
        } // 'basic' translations (ext_translations table)
        else {
            return \array_map(function ($element) use ($fieldName) {
                return $element[$fieldName] ?? null;
            }, $this->translationRepository->findTranslations($entity));
        }
    }

    private function getEntityInDefaultLocale($entity)
    {
        $class = \get_class($entity);
        $identifierField = $this->em->getClassMetadata($class)->getIdentifier()[0]; // <- none composite keys only
        $identifierValue = $this->propertyAccessor->getValue($entity, $identifierField);

        try {
            return $this->em->getRepository($class)->createQueryBuilder('entity')
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

    private function setFieldInDefaultLocale($entity, $field, $value)
    {
        $class = \get_class($entity);
        $identifierField = $this->em->getClassMetadata($class)->getIdentifier()[0];
        $identifierValue = $this->propertyAccessor->getValue($entity, $identifierField);

        $qb = $this->em->getRepository($class)->createQueryBuilder('entity');

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
    public function getTranslatedFields($entity, string $fieldName)
    {
        // translations
        $translations = $this->getTranslations($entity, $fieldName);

        $entity = $this->getEntityInDefaultLocale($entity);
        // translations + default locale value
        $translations[$this->defaultLocale] = $entity ? $this->propertyAccessor->getValue($entity, $fieldName) : null;

        return $translations;
    }

    private function getPersonalTranslationClassName($entity)
    {
        $metadata = $this->em->getClassMetadata(\get_class($entity));
        return $metadata->getAssociationTargetClass('translations');
    }

    private function getEntityId($entity)
    {
        $identifierField = $this->em->getClassMetadata(\get_class($entity))->getIdentifier()[0];
        return $this->propertyAccessor->getValue($entity, $identifierField);
    }

    // remove record from 'ext_translation' table
    private function removeTranslation($locale, $fieldName, $class, $foreignId)
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

    // remove personal translations
    private function removePersonalTranslation($locale, $fieldName, $class, $objectId)
    {

        $translationClass = null;
        $rc = new \ReflectionClass($class);
        do {
            if (null !== $this->annotationReader->getClassAnnotation($rc, TranslationEntity::class)) {
                $translationClass = $this->annotationReader->getClassAnnotation($rc, TranslationEntity::class)->class;
                break;
            }
        } while ($rc = $rc->getParentClass());

        $qb = $this->em->getRepository($translationClass)->createQueryBuilder('t');

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
    public function persistTranslations(Form $form, $locales)
    {
        $entity = $form->getParent()->getData();
        $fieldName = $form->getName();
        $submittedValues = $form->getData();

        $removeQueries = array();
        $personalTranslations = \method_exists($entity, self::GEDMO_PERSONAL_TRANSLATIONS_SET) && \is_callable(array(
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
                    } elseif($entityId) {
                        if ($personalTranslations && $entity->{self::GEDMO_PERSONAL_TRANSLATIONS_FIND}($locale,
                                $fieldName)) {
                            // remove - not default locale - personal
                            $removeQueries[] = $this->removePersonalTranslation($locale, $fieldName,
                                \get_class($entity), $entityId);
                        } else {
                            // remove - not default locale - external
                            $removeQueries[] = $this->removeTranslation($locale, $fieldName, \get_class($entity),
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
            $this->em->transactional(function () use ($removeQueries) {
                foreach ($removeQueries as $deletequery) {
                    $deletequery->execute();
                }
            });
        }

        $this->em->persist($entity);
    }
}
