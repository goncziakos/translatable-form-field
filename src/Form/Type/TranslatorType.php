<?php

namespace Bnh\TranslatableFieldBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Bnh\TranslatableFieldBundle\Helpers\TranslatableFieldManager as TranslatableFieldManager;


class TranslatorType extends AbstractType
{
    private string $currentLocale;

    public function __construct(
        private array $locales,
        protected TranslatableFieldManager $translatableFieldManager,
        protected TranslatorInterface $translator
    ) {
        $this->currentLocale = $translator->getLocale();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locales = $this->locales;

        // set fields
        $builder->addEventListener(FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($locales, $options) {
                $form = $event->getForm();

                foreach ($locales as $locale) {
                    $form->add($locale, $options['form_type'], ['label' => false]);
                }
            });

        // submit
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($locales) {
            $this->translatableFieldManager->persistTranslations($event->getForm(), $locales);
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $translatedFieldValues = $this->translatableFieldManager->getTranslatedFields($form->getParent()->getData(),
            $form->getName());

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

    public function getName(): string
    {
        return 'bnhtranslations';
    }

    private function getTabLabels(): array
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
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(array(
            'mapped' => false,
            'required' => false,
            'by_reference' => false,
            'form_type' => TextType::class,
        ));
    }

    public function getBlockPrefix(): string
    {
        return $this->getName();
    }
}
