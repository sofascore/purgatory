# SofascorePurgatoryBundle

[![Latest Stable Version](https://poser.pugx.org/sofascore/purgatory-bundle/v/stable)](https://packagist.org/packages/sofascore/purgatory-bundle)
[![Build Status](https://github.com/sofascore/purgatory-bundle/workflows/Tests/badge.svg)](https://github.com/sofascore/purgatory-bundle/actions)
[![Code Coverage](https://codecov.io/gh/sofascore/purgatory-bundle/graph/badge.svg?token=HWMVLVSTIC)](https://codecov.io/gh/sofascore/purgatory-bundle)
[![License](https://poser.pugx.org/sofascore/purgatory-bundle/license)](https://packagist.org/packages/sofascore/purgatory-bundle)

A Symfony bundle designed to automatically generate and send cache purge requests to HTTP cache backends like Varnish.
It leverages Doctrine events to track changes in entities and generates URLs that need to be purged based on configured
routes.

## Features

- **Doctrine Event Integration**: Listens to **Doctrine** lifecycle events (`postUpdate`, `postRemove`, `postPersist`)
  to automatically detect when entities are modified, created, or deleted.

- **Automatic URL Generation**: Automatically generates purge requests for relevant URLs based on the affected entities
  and their associated routes.

- **Flexible Configuration**:
    - Primary configuration is through the PHP attribute, `#[PurgeOn]`, allowing you to directly annotate entity classes
      with cache purge rules.
    - Supports YAML configuration for flexibility depending on your project's requirements.

- **Built-in Purger Support**: Comes with built-in support for **Symfony HTTP Cache** and a basic **Varnish**
  implementation. For advanced use cases, you can create custom purgers by implementing the `PurgerInterface`.

- **Asynchronous Processing**: Includes built-in support for **Symfony Messenger** to process purge requests
  asynchronously for better scalability and efficiency.

## Requirements

- [PHP 8.1](http://php.net/releases/8_1_0.php) or higher
- [Symfony 5.4](https://symfony.com/roadmap/5.4) or [Symfony 6.4](https://symfony.com/roadmap/6.4) or higher

## Installation

Require the bundle using [Composer](https://getcomposer.org/):

```sh
composer require sofascore/purgatory-bundle
```

If your project doesn't use [Symfony Flex](https://github.com/symfony/flex), continue with the following steps:

1. Create a configuration file under `config/packages/purgatory.yaml`. Here's a reference configuration:

    ```yaml
    purgatory:

        # List of files or directories where Purgatory will look for additional purge definitions.
        mapping_paths:        []

        # Route names that match the given regular expressions will be ignored.
        route_ignore_patterns: []

            # Examples:
            # - /^_profiler/
            # - /^_wdt/
        doctrine_middleware:
            enabled:              true

            # Explicitly set the priority of Purgatory's Doctrine middleware.
            priority:             null

        # Explicitly set the priorities of Purgatory's Doctrine event listener.
        doctrine_event_listener_priorities:
            preRemove:            null
            postPersist:          null
            postUpdate:           null

            # This event is not registered when the Doctrine middleware is enabled.
            postFlush:            null
        purger:

            # The ID of a service that implements the "Sofascore\PurgatoryBundle\Purger\PurgerInterface" interface
            name:                 null # Example: symfony

            # The hosts from which URLs should be purged
            hosts:                []

            # The service ID of the HTTP client to use, must be an instance of Symfony's HTTP client
            http_client:          null
        messenger:

            # Set the name of the messenger transport to use
            transport:            null

            # Set the name of the messenger bus to use
            bus:                  null

            # Set the number of urls to dispatch per message
            batch_size:           null

        # Enables the data collector and profiler panel if the profiler is enabled.
        profiler_integration: true
    ```

1. Enable the bundle in `config/bundles.php` by adding it to the array:

    ```php
    Sofascore\PurgatoryBundle\PurgatoryBundle::class => ['all' => true],
    ```

## Usage

For detailed instructions and examples, refer to the [documentation](https://sofascore.github.io/purgatory-bundle/).

## Versioning

This project follows [Semantic Versioning 2.0.0](http://semver.org/).

## Reporting Issues

Use the [issue tracker](https://github.com/sofascore/purgatory-bundle/issues) to report any issues you encounter.

## License

See the [LICENSE](LICENSE) file for details (MIT).
