<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\RouteMetadata;

use Sofascore\PurgatoryBundle\Attribute\PurgeOn;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\CompoundValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\DynamicValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\EnumValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\PropertyValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\RawValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\ValuesInterface;
use Sofascore\PurgatoryBundle\Attribute\Target\ForGroups;
use Sofascore\PurgatoryBundle\Attribute\Target\ForProperties;
use Sofascore\PurgatoryBundle\Attribute\Target\TargetInterface;
use Sofascore\PurgatoryBundle\Exception\InvalidArgumentException;
use Sofascore\PurgatoryBundle\Exception\RouteNotFoundException;
use Sofascore\PurgatoryBundle\Exception\RuntimeException;
use Sofascore\PurgatoryBundle\Exception\UnknownYamlTagException;
use Sofascore\PurgatoryBundle\Listener\Enum\Action;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser as YamlParser;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal Used during cache warmup
 */
final class YamlMetadataProvider implements RouteMetadataProviderInterface
{
    private const ALLOWED_KEYS = ['class', 'target', 'route_params', 'if', 'actions', 'context'];
    private ?YamlParser $yamlParser = null;

    /**
     * @param list<string> $files
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly array $files,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function provide(): iterable
    {
        $this->yamlParser ??= new YamlParser();

        $routeCollection = $this->router->getRouteCollection();

        foreach ($this->files as $file) {
            try {
                /** @var array<string, array<string, mixed>|list<array<string, mixed>>>|scalar|null $configuration */
                $configuration = $this->yamlParser->parseFile($file, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException $e) {
                throw new InvalidArgumentException(\sprintf('The file "%s" does not contain valid YAML: ', $file).$e->getMessage(), previous: $e);
            }

            if (!\is_array($configuration)) {
                throw new RuntimeException(\sprintf('Expected the parsed YAML of file "%s" to be an array, got "%s".', $file, get_debug_type($configuration)));
            }

            yield from $this->provideFromFile($configuration, $routeCollection);
        }
    }

    /**
     * @param array<string, array<string, mixed>|list<array<string, mixed>>> $configuration
     *
     * @return iterable<RouteMetadata>
     */
    private function provideFromFile(array $configuration, RouteCollection $routeCollection): iterable
    {
        foreach ($configuration as $routeName => $purgeOns) {
            if (!array_is_list($purgeOns)) {
                $purgeOns = [$purgeOns];
            }

            /**
             * @var array{
             *     class: class-string,
             *     target?: string|non-empty-list<string>|TaggedValue|null,
             *     route_params?: ?non-empty-array<string, string|non-empty-list<string>|TaggedValue>,
             *     if?: ?string,
             *     actions?: value-of<Action>|non-empty-list<value-of<Action>|Action>|Action|null,
             *     context?: array<string, ?scalar>,
             * } $purgeOn
             */
            foreach ($purgeOns as $purgeOn) {
                $this->validate($purgeOn, $routeName);

                yield new RouteMetadata(
                    routeName: $routeName,
                    route: $routeCollection->get($routeName) ?? throw new RouteNotFoundException($routeName),
                    purgeOn: $this->buildPurgeOn($purgeOn),
                    reflectionMethod: null,
                );
            }
        }
    }

    /**
     * @param array{
     *     class: class-string,
     *     target?: string|non-empty-list<string>|TaggedValue|null,
     *     route_params?: ?non-empty-array<string, string|non-empty-list<string>|TaggedValue>,
     *     if?: ?string,
     *     actions?: value-of<Action>|non-empty-list<value-of<Action>|Action>|Action|null,
     *     context?: array<string, ?scalar>,
     * } $purgeOn
     */
    private function validate(array $purgeOn, string $routeName): void
    {
        if ($invalidKeys = array_diff(array_keys($purgeOn), self::ALLOWED_KEYS)) {
            throw new InvalidArgumentException(\sprintf(
                'Route "%s" contains unsupported keys "%s", supported ones are "%s".',
                $routeName,
                implode('", "', $invalidKeys),
                implode('", "', self::ALLOWED_KEYS),
            ));
        }
    }

    /**
     * @param array{
     *     class: class-string,
     *     target?: string|non-empty-list<string>|TaggedValue|null,
     *     route_params?: ?non-empty-array<string, string|non-empty-list<string>|TaggedValue>,
     *     if?: ?string,
     *     actions?: value-of<Action>|non-empty-list<value-of<Action>|Action>|Action|null,
     *     context?: array<string, ?scalar>,
     * } $purgeOn
     */
    private function buildPurgeOn(array $purgeOn): PurgeOn
    {
        return new PurgeOn(
            class: $purgeOn['class'],
            target: isset($purgeOn['target']) ? $this->buildTarget($purgeOn['target']) : null,
            routeParams: isset($purgeOn['route_params']) ? array_map($this->buildRouteParam(...), $purgeOn['route_params']) : null,
            if: $purgeOn['if'] ?? null,
            actions: $purgeOn['actions'] ?? null,
            context: $purgeOn['context'] ?? [],
        );
    }

    /**
     * @param string|non-empty-list<string>|TaggedValue $target
     *
     * @return string|non-empty-list<string>|TargetInterface
     */
    private function buildTarget(string|array|TaggedValue $target): string|array|TargetInterface
    {
        if (!$target instanceof TaggedValue) {
            return $target;
        }

        /** @var string|non-empty-list<string> $value */
        $value = $target->getValue();

        return match ($tag = $target->getTag()) {
            'for_groups' => new ForGroups($value),
            'for_properties' => new ForProperties($value),
            default => throw new UnknownYamlTagException($tag, ['for_groups', 'for_properties']),
        };
    }

    /**
     * @param string|non-empty-list<string>|TaggedValue $routeParam
     *
     * @return string|non-empty-list<string>|ValuesInterface
     */
    private function buildRouteParam(string|array|TaggedValue $routeParam): string|array|ValuesInterface
    {
        if (!$routeParam instanceof TaggedValue) {
            return $routeParam;
        }

        /** @var scalar|non-empty-list<scalar>|non-empty-list<TaggedValue> $value */
        $value = $routeParam->getValue();

        return match ($tag = $routeParam->getTag()) {
            CompoundValues::type() => new CompoundValues(...array_map($this->buildRouteParam(...), $value)),
            DynamicValues::type() => new DynamicValues(...((array) $value)),
            EnumValues::type() => new EnumValues($value),
            PropertyValues::type() => new PropertyValues(...((array) $value)),
            RawValues::type() => new RawValues(...((array) $value)),
            default => throw new UnknownYamlTagException($tag, [
                CompoundValues::type(),
                DynamicValues::type(),
                EnumValues::type(),
                PropertyValues::type(),
                RawValues::type(),
            ]),
        };
    }
}
