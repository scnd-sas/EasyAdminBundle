<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Form\Guesser;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Symfony\Bridge\Doctrine\Form\DoctrineOrmTypeGuesser;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;

class MissingDoctrineOrmTypeGuesser extends DoctrineOrmTypeGuesser
{
    /**
     * {@inheritdoc}
     */
    public function guessType($class, $property): ?TypeGuess
    {
        if (null !== $metadataAndName = $this->getMetadata($class)) {
            /** @var ClassMetadataInfo $metadata */
            [$metadata] = $metadataAndName;

            switch ($metadata->getTypeOfField($property)) {
                case 'datetime_immutable': // available since Doctrine 2.6
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\DateTimeType', [], Guess::HIGH_CONFIDENCE);
                case 'date_immutable': // available since Doctrine 2.6
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\DateType', [], Guess::HIGH_CONFIDENCE);
                case 'time_immutable': // available since Doctrine 2.6
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\TimeType', [], Guess::HIGH_CONFIDENCE);
                case Types::SIMPLE_ARRAY:
                case Types::JSON:
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\CollectionType', [], Guess::MEDIUM_CONFIDENCE);
                case 'json': // available since Doctrine 2.6.2
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\TextareaType', [], Guess::MEDIUM_CONFIDENCE);
                case Types::OBJECT:
                case Types::BLOB:
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\TextareaType', [], Guess::MEDIUM_CONFIDENCE);
                case Types::GUID:
                    return new TypeGuess('Symfony\Component\Form\Extension\Core\Type\TextType', [], Guess::MEDIUM_CONFIDENCE);
            }
        }

        return parent::guessType($class, $property);
    }
}
