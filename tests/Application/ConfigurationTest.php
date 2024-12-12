<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Tests\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\CompoundValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\DynamicValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\EnumValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\PropertyValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\RawValues;
use Sofascore\PurgatoryBundle\Cache\Configuration\Configuration;
use Sofascore\PurgatoryBundle\Listener\Enum\Action;
use Sofascore\PurgatoryBundle\Tests\Functional\AbstractKernelTestCase;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Controller\AnimalController;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Controller\CompetitionController;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Controller\PersonController;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Controller\VehicleController;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Entity\Animal;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Entity\Car;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Entity\Competition\Competition;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Entity\Person;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Entity\Vehicle;
use Sofascore\PurgatoryBundle\Tests\Functional\TestApplication\Enum\Country;
use Symfony\Component\HttpKernel\Kernel;

final class ConfigurationTest extends AbstractKernelTestCase
{
    private static ?Configuration $configuration;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::initializeApplication(['test_case' => 'TestApplication']);

        self::$configuration = self::getContainer()->get('sofascore.purgatory.configuration_loader')->load();

        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$configuration = null;

        parent::tearDownAfterClass();
    }

    #[DataProvider('configurationWithoutTargetProvider')]
    public function testConfigurationWithoutTarget(string $entity, array $subscription): void
    {
        self::assertSubscriptionExists(
            key: $entity,
            subscription: $subscription,
        );
    }

    #[DataProvider('configurationWithTargetProvider')]
    public function testConfigurationWithTarget(string $entity, array $properties, array $subscription): void
    {
        foreach ($properties as $property) {
            self::assertSubscriptionExists(
                key: $entity.'::'.$property,
                subscription: $subscription,
            );
        }
    }

    private static function assertSubscriptionExists(string $key, array $subscription): void
    {
        self::assertTrue(
            condition: self::$configuration->has($key),
            message: \sprintf('Failed asserting that the configuration contains a subscription for "%s".', $key),
        );

        self::assertContains(
            needle: $subscription,
            haystack: self::$configuration->get($key),
            message: \sprintf('Failed asserting that the configuration contains the subscription "%s" for the key "%s".', json_encode($subscription), $key),
        );
    }

    public static function configurationWithoutTargetProvider(): iterable
    {
        /* @see PersonController::detailsAction */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'person_details',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see PersonController::personListMaleAction */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'person_list_men',
                'if' => 'obj.gender === "male"',
            ],
        ];

        /* @see PersonController::personListCustomElfAction */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'person_list_custom_elf',
                'if' => 'custom_elf(obj)',
            ],
        ];

        /* @see PersonController::petsAction */
        yield [
            'entity' => Animal::class,
            'subscription' => [
                'routeName' => 'pets_list',
                'routeParams' => [
                    'person' => [
                        'type' => PropertyValues::type(),
                        'values' => [
                            Kernel::MAJOR_VERSION > 5 ? 'owner?.id' : 'owner.id',
                        ],
                    ],
                ],
            ],
        ];

        /* @see PersonController::petsActionAlternative */
        yield [
            'entity' => Animal::class,
            'subscription' => [
                'routeName' => 'pets_list_alt',
                'routeParams' => [
                    'person' => [
                        'type' => PropertyValues::type(),
                        'values' => ['owner.id'],
                    ],
                ],
            ],
        ];

        /* @see PersonController::petsPaginatedAction */
        yield [
            'entity' => Animal::class,
            'subscription' => [
                'routeName' => 'pets_paginated',
                'routeParams' => [
                    'person' => [
                        'type' => PropertyValues::type(),
                        'values' => ['owner.id'],
                    ],
                    'page' => [
                        'type' => RawValues::type(),
                        'values' => [0, 1],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::animalsForRatingAction */
        yield [
            'entity' => Animal::class,
            'subscription' => [
                'routeName' => 'animals_with_rating',
                'routeParams' => [
                    'rating' => [
                        'type' => CompoundValues::type(),
                        'values' => [
                            [
                                'type' => DynamicValues::type(),
                                'values' => ['purgatory.animal_rating3', 'owner'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::petOwnerDetails */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'pet_owner_details',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['pets[*].id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::petOwnerDetailsAlternative() */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'pet_owner_details_alternative',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['petsIds'],
                    ],
                ],
            ],
        ];

        /* @see PersonController::deletedPersonsAction */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'deleted_persons',
                'actions' => [Action::Delete],
            ],
        ];

        /* @see PersonController::allIdsAction */
        yield [
            'entity' => Person::class,
            'subscription' => [
                'routeName' => 'all_ids',
                'actions' => [Action::Create, Action::Delete],
            ],
        ];

        /* @see PersonController::personCarsList */
        yield [
            'entity' => Car::class,
            'subscription' => [
                'routeName' => 'person_cars_list',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => [
                            Kernel::MAJOR_VERSION > 5 ? 'owner?.id' : 'owner.id',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function configurationWithTargetProvider(): iterable
    {
        /* @see PersonController::petsNamesAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['name'],
            'subscription' => [
                'routeName' => 'pets_names',
                'routeParams' => [
                    'person' => [
                        'type' => PropertyValues::type(),
                        'values' => ['owner.id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::detailAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['name', 'measurements.height', 'measurements.width', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'animal_details',
                'routeParams' => [
                    'animal_id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::measurementsAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['measurements.height', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'animal_measurements',
                'routeParams' => [
                    'animal_id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::someRandomRouteAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['measurements.height', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'animal_route_1',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::someRandomRouteAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['measurements.height', 'measurements.width'],
            'subscription' => [
                'routeName' => 'animal_route_2',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::petOfTheDayAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['name', 'measurements.height', 'measurements.width', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'pet_of_the_day',
                'routeParams' => [
                    'country' => [
                        'type' => EnumValues::type(),
                        'values' => [Country::class],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::petOfTheMonthAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['name', 'measurements.height', 'measurements.width', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'pet_of_the_month',
                'routeParams' => [
                    'country' => [
                        'type' => CompoundValues::type(),
                        'values' => [
                            [
                                'type' => EnumValues::type(),
                                'values' => [Country::class],
                            ],
                            [
                                'type' => RawValues::type(),
                                'values' => ['ar'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::animalsForRatingAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['measurements.height', 'measurements.width', 'measurements.weight'],
            'subscription' => [
                'routeName' => 'animals_with_rating',
                'routeParams' => [
                    'rating' => [
                        'type' => CompoundValues::type(),
                        'values' => [
                            [
                                'type' => DynamicValues::type(),
                                'values' => ['purgatory.animal_rating2', null],
                            ],
                            [
                                'type' => DynamicValues::type(),
                                'values' => ['purgatory.animal_rating1', null],
                            ],
                            [
                                'type' => DynamicValues::type(),
                                'values' => ['purgatory.animal_rating3', 'owner'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /* @see PersonController::personListForCountryAction */
        yield [
            'entity' => Person::class,
            'properties' => ['country'],
            'subscription' => [
                'routeName' => 'person_list_for_country',
                'routeParams' => [
                    'country' => [
                        'type' => CompoundValues::type(),
                        'values' => [
                            [
                                'type' => PropertyValues::type(),
                                'values' => ['alpha2'],
                            ],
                            [
                                'type' => RawValues::type(),
                                'values' => [null],
                            ],
                        ],
                        'optional' => true,
                    ],
                ],
            ],
        ];

        /* @see CompetitionController::orderedCompetitionsAction */
        yield [
            'entity' => Competition::class,
            'properties' => ['numberOfPets'],
            'subscription' => [
                'routeName' => 'competitions_ordered_by_number_of_pets',
            ],
        ];

        /* @see CompetitionController::competitionsByWinnerAction */
        yield [
            'entity' => Competition::class,
            'properties' => ['winner'],
            'subscription' => [
                'routeName' => 'competitions_by_winner',
                'routeParams' => [
                    'winner_id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['winner.id'],
                    ],
                ],
            ],
        ];

        /* @see VehicleController::numberOfEnginesAction */
        yield [
            'entity' => Vehicle::class,
            'properties' => ['numberOfEngines'],
            'subscription' => [
                'routeName' => 'number_of_engines',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['id'],
                    ],
                ],
            ],
        ];

        /* @see AnimalController::animalsForVeterinarianAction */
        yield [
            'entity' => Animal::class,
            'properties' => ['veterinarian'],
            'subscription' => [
                'routeName' => 'animals_for_veterinarian',
                'routeParams' => [
                    'id' => [
                        'type' => PropertyValues::type(),
                        'values' => ['veterinarian?.id'],
                    ],
                ],
                'if' => 'obj.name === "Sharp Dressed Dog"',
            ],
        ];
    }
}
