<?php

namespace Bnh\TranslatableFieldBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Bnh\TranslatableFieldBundle\Helpers\TranslatableFieldManager as TranslatableFieldManager;

class TranslatorType extends AbstractType
{

    protected $translatablefieldmanager;
    private $locales;
    private $defaultLocale;
    private $currentLocale;

    public function __construct($defaultLocale, $localeCodes, TranslatableFieldManager $translatableFieldManager, TranslatorInterface $translator)
    {
        $this->defaultLocale = $defaultLocale;
        $this->locales = $localeCodes;
        $this->translatablefieldmanager = $translatableFieldManager;
        $this->currentLocale = $translator->getLocale();
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $fieldName = $builder->getName();
        $locales = $this->locales;
        $defaultLocale = $this->defaultLocale;

        // set fields
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($fieldName, $locales) {
            $form = $event->getForm();

            foreach ($locales as $locale) {
                $form->add($locale, 'text', ['label' => false]);
            }
        });

        // submit
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($fieldName, $locales, $defaultLocale) {
            $this->translatablefieldmanager->persistTranslations($event->getForm(), $locales, $defaultLocale);
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $translatedFieldValues = $this->translatablefieldmanager->getTranslatedFields($form->getParent()->getData(), $form->getName(), $this->defaultLocale);

        // set form field data (translations)
        foreach ($this->locales as $locale) {
            if (!isset($translatedFieldValues[$locale])) {
                continue;
            }

            $form->get($locale)->setData($translatedFieldValues[$locale]);
        }

        // template vars
        $view->vars['locales'] = $this->locales;
        $view->vars['currentlocale'] = $this->currentLocale;
        $view->vars['tablabels'] = $this->getTabLabels();
    }

    public function getName()
    {
        return 'bnhtranslations';
    }

    private function getTabLabels()
    {
        $tabLabels = array();
        foreach ($this->locales as $locale) {
            $tabLabels[$locale] = \Locale::getDisplayLanguage($locale, $this->currentLocale);
        }

        return $tabLabels;
    }

    /**
     * {@inheritDoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array
            (
            'mapped' => false
            , 'required' => false
            , 'by_reference' => false
            )
        );
    }
}
