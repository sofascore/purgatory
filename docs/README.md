# Getting Started

The bundle is designed to automatically generate and send cache purge requests to HTTP cache backends such as Symfony's
HTTP cache or Varnish. It leverages Doctrine events to track changes in entities and generates URLs that need to be
purged based on configured routes.

## Why URL-based Invalidation?

This bundle uses URL-based invalidation instead of tag-based invalidation due to the following reasons:

1. **Performance Concerns**: Varnish's tag-based invalidation can lead to slow responses when multiple URLs are
   invalidated simultaneously.
1. **Header Size Limitations**: Tags are typically passed through HTTP headers, which have size limitations. This means
   not all tags may fit within the header limits.
1. **Cost Implications**: Some CDN providers charge extra for tag-based invalidation, making URL-based purging a more
   cost-effective solution.

## Supported Backends

The bundle includes built-in support for [Symfony HTTP Cache](https://symfony.com/doc/current/http_cache.html) and a
basic [Varnish](https://varnish-cache.org/) implementation. Each backend is realized by implementing
the [`PurgerInterface`](/src/Purger/PurgerInterface.php).

It also comes with a `void`, which can be used during development when cache purging is not required. The `void` simply
ignores all purge requests, making it ideal for non-production environments. Additionally, an `in-memory` is provided,
designed specifically for testing purposes. The `in-memory` simulates purging actions without interacting with external
cache services, enabling you to verify your purging logic in tests.

For advanced use cases, you can create [custom purgers](/docs/custom-purgers.md). This allows you to integrate with any
custom or third-party HTTP cache backend that fits your project requirements.

### Configuring Symfony's HTTP Cache

Configure Symfony's HTTP Cache according to
the [official documentation](https://symfony.com/doc/current/http_cache.html#symfony-reverse-proxy).

Configure the `symfony` purger in your configuration:

```yaml
# config/packages/purgatory.yaml
purgatory:
    purger: symfony
```

### Configuring Varnish Cache

To enable Varnish to support `PURGE` requests, add the following example configuration to your VCL file. You may need to
customize it based on your specific Varnish setup:

```vcl
acl purge {
    "localhost";
    "172.16.0.0"/12; # Common Docker IP range, adjust as needed
    # Add more whitelisted IPs here
}

sub vcl_recv {
    if (req.method == "PURGE") {
        if (client.ip !~ purge) {
            return (synth(405, "Not allowed."));
        }
        return (purge);
    }
}
```

Configure the `varnish` purger in your configuration:

```yaml
# config/packages/purgatory.yaml
purgatory:
    purger: varnish
```

Optionally, you can specify a list of Varnish hosts:

```yaml
# config/packages/purgatory.yaml
purgatory:
    purger:
        name: varnish
        hosts:
            - varnish1.example.com
            - varnish2.example.com
            - varnish3.example.com
```

If no hosts are specified, the bundle will use the host from the URL.

## Configuring Asynchronous Processing

Purge requests can be processed asynchronously using
the [Symfony Messenger component](https://symfony.com/doc/current/messenger.html). To enable asynchronous processing,
simply set up the transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'

# config/packages/purgatory.yaml
purgatory:
    messenger:
        transport: async
```

Then, run the `messenger:consume` command:

```shell
bin/console messenger:consume async
```

## How It Works

The bundle listens to **Doctrine** lifecycle events (`postUpdate`, `postRemove`, `postPersist`) to automatically detect
when entities are modified, created, or deleted. When these changes are flushed to the database, the bundle steps in to
process them.

The bundle uses **purge subscriptions**, which are predefined rules that associate specific entities and their
properties with corresponding routes and route parameters. These subscriptions help identify which content should be
purged based on changes to the entities.

To determine which routes need purging, the bundle relies on **route providers**. These services evaluate the purge
subscriptions and determine the relevant routes and parameters based on the changes detected in the entities.

Using this information, the bundle generates the URLs that need to be purged. It then sends these purge requests to the
configured purger, which clears the cached content for those URLs.

You can also create [custom route providers](/docs/custom-route-providers.md) to define additional routes for specific
entities and properties, giving you greater control over purging behavior in more complex scenarios.

## Configuring Purge Subscriptions

Purge subscriptions can be configured using the [`#[PurgeOn]`](/src/Attribute/PurgeOn.php) attribute.

You can also configure purge subscriptions [using YAML](/docs/purge-subscriptions-using-yaml.md). This is particularly
useful if you have routes without an associated controller or action.

### Basic Example

In this example, the post details page is purged whenever any change is made to the `Post` entity:

```php
use Sofascore\PurgatoryBundle\Attribute\PurgeOn;
use Symfony\Component\Routing\Attribute\Route;

class PostController
{
    #[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
    #[PurgeOn(Post::class)]
    public function detailsAction(Post $post)
    {
    }
}
```

Here, the `id` property is automatically mapped to the route parameter with the same name.

### Explicit Mapping of Route Parameters

If the parameter names differ, you can explicitly map them:

```php
#[Route('/post/{postId<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, routeParams: ['postId' => 'id'])]
public function detailsAction(Post $post)
{
}
```

For more advanced examples of mapping route parameters, see the [dedicated section](/docs/complex-route-params.md).

### Targeting Specific Properties

By default, all properties are subscribed to purging. You can customize this by specifying which properties to watch:

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, target: ['title', 'text'])]
public function detailsAction(Post $post)
{
}
```

In this example, the purge will only occur if the `title` or `text` properties change.

### Targeting Specific Methods

In addition to properties, you can specify methods that define which properties should be watched:

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, target: 'titleAndAuthor')]
public function detailsAction(Post $post)
{
}
```

To associate specific properties with a method, use the [`#[TargetedProperties]`](/src/Attribute/TargetedProperties.php)
attribute on your entity method:

```php
use Doctrine\ORM\Mapping as ORM;
use Sofascore\PurgatoryBundle\Attribute\TargetedProperties;

#[ORM\Entity]
class Post
{
    // ...

    #[TargetedProperties('title', 'author')]
    public function getTitleAndAuthor(): string
    {
        return $this->title.', '.$this->author->getFullName();
    }
}
```

In this example, the purge will only occur if the `title` or `author` properties change.

### Using Serialization Groups

You can also specify which Symfony
Serializer [serialization groups](https://symfony.com/doc/current/serializer.html#using-serialization-groups-attributes)
to use:

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, target: new ForGroups('common'))]
public function detailsAction(Post $post)
{
}
```

Now, the purge will occur for all properties that are part of the `common` serialization group.

### Adding Conditional Logic with Expression Language

[Symfony's Expression Language component](https://symfony.com/doc/current/components/expression_language.html) can be
used to add conditions that must be met for the purge to occur. In these expressions, the entity is available as the
`obj` variable:

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, if: 'obj.upvotes > 3000')]
public function detailsAction(Post $post)
{
}
```

In this example, the purge will only occur if the post has more than 3,000 upvotes.

You can also add [custom Expression Language functions](/docs/custom-expression-language-functions.md).

### Limiting Purge to Specific Routes

By default, the attribute generates URLs for all routes associated with the action. You can limit this to one or more
specific routes:

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[Route('/blog-post/{id<\d+>}', name: 'post_details_old', methods: 'GET')]
#[PurgeOn(Post::class, route: 'post_details')]
public function detailsAction(Post $post)
{
}
```

In this example, only the `post_details` route will be purged.

### Limiting by Action Type

You can also limit the purging to a specific action as defined in the [`Action`](/src/Listener/Enum/Action.php) enum:

```php
use Sofascore\PurgatoryBundle\Listener\Enum\Action;

#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, action: Action::Update)]
public function detailsAction(Post $post)
{
}
```

Now, the purge will only occur when the entity is updated, but not when it is created or deleted.

## Testing

For testing purposes, you can use the `in-memory` purger along with
the [`InteractsWithPurgatory`](/src/Test/InteractsWithPurgatory.php) trait.

Configure the `in-memory` purger in your configuration:

```yaml
# config/packages/purgatory.yaml
purgatory:
    purger: in-memory
```

To write tests, use the `InteractsWithPurgatory` trait in your test class, which provides helper methods to verify
purged URLs and clear the in-memory purger:

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Sofascore\PurgatoryBundle\Test\InteractsWithPurgatory;

class PurgeTest extends KernelTestCase
{
    use InteractsWithPurgatory;

    // ...

    public function testPurgePost()
    {
        // Create and persist a new Post entity
        $post = new Post();
        $post->title = 'Title';
        $post->text = 'Text';

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // Assert that the URL for the post has been purged
        self::assertUrlIsPurged('/post/title');

        // Clear the in-memory purger for the next set of assertions
        self::clearPurger();

        // Update the Post entity and flush the changes
        $post->title = 'Title New';

        $this->entityManager->flush();

        // Assert that both the old and new URLs have been purged
        self::assertUrlIsPurged('/post/title');
        self::assertUrlIsPurged('/post/title-new');
    }
}
```

## Debugging

The bundle includes integration with the [Symfony Profiler](https://symfony.com/doc/current/profiler.html) to help you
monitor and troubleshoot purge requests. To enable this integration, add the following configuration:

```yaml
# config/packages/purgatory.yaml
purgatory:
    profiler_integration: true
```

Additionally, you can use the `purgatory:debug` command to display information about all configured purge subscriptions.
This command provides insights into which routes and parameters are associated with your entities.

## See Also

- [Custom Purgers](/docs/custom-purgers.md)
- [Custom Route Providers](/docs/custom-route-providers.md)
- [Complex Route Parameters](/docs/complex-route-params.md)
- [Configure Purge Subscriptions Using YAML](/docs/purge-subscriptions-using-yaml.md)
- [Custom Expression Language Functions](/docs/custom-expression-language-functions.md)
