# translatable-form-field
This bundle is responsible for translatable form fields in symfony4 sonata admin.

Usage:

- add to config/bundles.php
```PHP
<?php

return [
    // ...
    Bnh\TranslatableFieldBundle\BnhTranslatableFieldBundle::class => ['all' => true],
];
```
- Create config

config/packages/bnh_translatable_field.yaml

```YAML
bnh_translatable_field:
    default_locale: en_GB
    locales: ['de_DE', 'en_GB', 'es_ES', 'fr_FR', 'hu_HU', 'ru_RU', 'sv_SE']
    templating: 'BnhTranslatableFieldBundle:FormType:bnhtranslations.html.twig'
```

- Check gedmo config

config/packages/stof_doctrine_extensions.yaml

```YAML
stof_doctrine_extensions:
  default_locale: '%locale%'
  translation_fallback: true
  orm:
    default:
      timestampable: true
```

- Check doctrine config

config/packages/doctrine.yaml

```YAML
doctrine:
    orm:
        mappings:
            gedmo_translatable:
                is_bundle: false
                type: annotation
                dir: '%kernel.project_dir%/vendor/gedmo/doctrine-extensions/lib/Gedmo/Translatable/Entity'
                prefix: 'Gedmo\Translatable\Entity'
                alias: GedmoTranslatable
```

- entity (ext_translations)

```PHP
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
// ...

/**
 * @ORM\Entity
 */
class YourEntity implements Translatable
{
    /**
     * @Gedmo\Translatable
     * @ORM\Column(...
     */
    private $fieldname;

    public function setfieldname($fieldname)
    {
        $this->fieldname = $fieldname;
        return $this;
    }

    /**
     * @Gedmo\Locale
     */
    private $locale;

    public function setTranslatableLocale($locale)
    {
        $this->locale = $locale;
    }
}
```

- entity (for personal translations)

```PHP
/**
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="YourEntityTranslation")
 */
class YourEntity
{
    /**
     * @ORM\OneToMany(targetEntity="YourEntityTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    private $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getTranslations()
    {
        return $this->translations;
    }

    public function addTranslation(YourEntityTranslation $newTranslation)
    {
        if($newTranslation->getContent())
        {
            $found = false;
            foreach($this->translations as $translation)
            {
                if(($translation->getLocale() === $newTranslation->getLocale()) && ($translation->getField() === $newTranslation->getField()))
                {
                    $found = true;
                    $translation->setContent($newTranslation->getContent());
                    break;
                }
            }
            
            if(!$found)
            {
                $newTranslation->setObject($this);
                $this->translations[] = $newTranslation;
            }
        }
    }

    public function hasTranslation($locale, $fieldName)
    {
        foreach ($this->translations as $translation)
        {
            if(($translation->getLocale() === $locale) && ($translation->getField() === $fieldName))
            {
                return true;
            }
        }
        
        return false;
    }
}
```

- sonata admin page
```PHP
    protected function configureFormFields(FormMapper $formMapper): void
    {
        $formMapper->add('fieldname', TranslatableFieldBundle\Form\Type\TranslatorType::class);
    }
```
