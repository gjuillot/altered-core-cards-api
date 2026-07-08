<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GameplayFormatFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = array_combine($options['format_choices'], $options['format_choices']);

        $builder
            ->add('cardNumber', TextType::class, [
                'label'    => 'Numéro de carte',
                'required' => false,
                'attr'     => ['placeholder' => 'Référence ou numéro de collection...'],
            ])
            ->add('gameplayFormat', ChoiceType::class, [
                'label'    => 'Gameplay format',
                'required' => false,
                'choices'  => array_merge(['Tous' => ''], $choices),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'GET',
            'csrf_protection' => false,
            'format_choices'  => [],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
