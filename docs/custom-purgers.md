# Custom Purgers

You can create custom purgers by implementing the [`PurgerInterface`][0]. This allows you to define your own logic for
handling purge requests. Once implemented, be sure to tag your custom purger with `purgatory.purger` to make it
available for configuration.

Here's an example of a custom purger for Cloudflare:

```php
use Sofascore\PurgatoryBundle\Purger\PurgerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('purgatory.purger', ['alias' => 'cloudflare'])]
class CloudflarePurger implements PurgerInterface
{
    public function purge(iterable $purgeRequests): void
    {
        // Your custom logic to send purge requests to Cloudflare
    }
}
```

### Enabling Your Custom Purger

To enable your custom purger, update your configuration file with the alias you specified:

```yaml
# config/packages/purgatory.yaml
purgatory:
    purger: cloudflare
```

In this example, the alias `cloudflare` is used to refer to the custom purger.

[0]: https://github.com/sofascore/purgatory-bundle/blob/1.x/src/Purger/PurgerInterface.php
