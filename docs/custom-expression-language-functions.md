# Custom Expression Language Functions

You can add custom functions to the Expression Language for use with the `if` parameter.

```php
#[Route('/post/{id<\d+>}', name: 'post_details', methods: 'GET')]
#[PurgeOn(Post::class, if: 'should_purge(obj)')]
public function detailsAction(Post $post)
{
}
```

To enable this functionality, make sure your service is tagged correctly in the service configuration:

```yaml
# services.yaml
App\ShouldPurge:
    tags:
        - { name: 'purgatory.expression_language_function', function: should_purge }
```

Alternatively, you can use the [`#[AsExpressionLanguageFunction]`][0] attribute directly in the service class:

```php
use Sofascore\PurgatoryBundle\Attribute\AsExpressionLanguageFunction;

#[AsExpressionLanguageFunction('should_purge')]
class ShouldPurge
{
    public function __invoke(Post $post)
    {
        // Define your custom logic here
    }
}
```

[0]: https://github.com/sofascore/purgatory-bundle/blob/1.x/src/Attribute/AsExpressionLanguageFunction.php
