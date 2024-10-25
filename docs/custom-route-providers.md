# Custom Route Providers

To create a custom route provider, implement
the [`RouteProviderInterface`](/src/RouteProvider/RouteProviderInterface.php). This interface defines two methods:

- **`supports` method**: Determines whether the route provider supports a given entity and action. This method helps
  filter which entities the provider should be responsible for.

- **`provideRoutesFor` method**: Generates and yields routes for the given entity. This method uses instances of
  [`PurgeRoute`](/src/RouteProvider/PurgeRoute.php) to define route names and their parameters. You can implement custom
  logic to determine the appropriate routes based on the action, the entity, and any changes detected in the entity's
  properties.

### Example

Here's an example of a custom route provider for handling `Post` entities:

```php
use Sofascore\PurgatoryBundle\RouteProvider\PurgeRoute;
use Sofascore\PurgatoryBundle\Listener\Enum\Action;
use Sofascore\PurgatoryBundle\RouteProvider\RouteProviderInterface;

class MyPostRouteProvider implements RouteProviderInterface
{
    public function provideRoutesFor(Action $action, object $entity, array $entityChangeSet): iterable
    {
        // Custom logic to determine routes based on the action, entity, and changes

        yield new PurgeRoute(
            name: 'route_name',
            params: [
                // Route parameters go here
            ]
        );
    }

    public function supports(Action $action, object $entity): bool
    {
        // Define the conditions under which this provider should be used
        return $entity instanceof Post;
    }
}
```

### Registering Your Custom Route Provider

If you're not using
Symfony's [autoconfigure](https://symfony.com/doc/current/service_container.html#the-autoconfigure-option) feature, you
need to manually tag the service:

```yaml
# services.yaml
App\RouteProvider\MyPostRouteProvider:
    tags:
        - { name: 'purgatory.route_provider' }
```

By tagging it with `purgatory.route_provider`, the bundle will automatically recognize and use your custom route
provider when processing purge requests.
