<?php

namespace Http\HttplugBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * This class contains the configuration information for the bundle.
 *
 * This information is solely responsible for how the different configuration
 * sections are normalized, and merged.
 *
 * @author David Buchmann <mail@davidbu.ch>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Whether to use the debug mode.
     *
     * @see https://github.com/doctrine/DoctrineBundle/blob/v1.5.2/DependencyInjection/Configuration.php#L31-L41
     *
     * @var bool
     */
    private $debug;

    /**
     * @param bool $debug
     */
    public function __construct($debug)
    {
        $this->debug = (bool) $debug;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('httplug');

        $this->configureClients($rootNode);
        $this->configureSharedPlugins($rootNode);

        $rootNode
            ->validate()
                ->ifTrue(function ($v) {
                    return !empty($v['classes']['client'])
                        || !empty($v['classes']['message_factory'])
                        || !empty($v['classes']['uri_factory'])
                        || !empty($v['classes']['stream_factory']);
                })
                ->then(function ($v) {
                    foreach ($v['classes'] as $key => $class) {
                        if (null !== $class && !class_exists($class)) {
                            throw new InvalidConfigurationException(sprintf(
                                'Class %s specified for httplug.classes.%s does not exist.',
                                $class,
                                $key
                            ));
                        }
                    }

                    return $v;
                })
            ->end()
            ->beforeNormalization()
                ->ifTrue(function ($v) {
                    return is_array($v) && array_key_exists('toolbar', $v) && is_array($v['toolbar']);
                })
                ->then(function ($v) {
                    if (array_key_exists('profiling', $v)) {
                        throw new InvalidConfigurationException('Can\'t configure both "toolbar" and "profiling" section. The "toolbar" config is deprecated as of version 1.3.0, please only use "profiling".');
                    }

                    @trigger_error('"httplug.toolbar" config is deprecated since version 1.3 and will be removed in 2.0. Use "httplug.profiling" instead.', E_USER_DEPRECATED);

                    if (array_key_exists('enabled', $v['toolbar']) && 'auto' === $v['toolbar']['enabled']) {
                        @trigger_error('"auto" value in "httplug.toolbar" config is deprecated since version 1.3 and will be removed in 2.0. Use a boolean value instead.', E_USER_DEPRECATED);
                        $v['toolbar']['enabled'] = $this->debug;
                    }

                    $v['profiling'] = $v['toolbar'];

                    unset($v['toolbar']);

                    return $v;
                })
            ->end()
            ->fixXmlConfig('client')
            ->children()
                ->arrayNode('main_alias')
                    ->addDefaultsIfNotSet()
                    ->info('Configure which service the main alias point to.')
                    ->children()
                        ->scalarNode('client')->defaultValue('httplug.client.default')->end()
                        ->scalarNode('message_factory')->defaultValue('httplug.message_factory.default')->end()
                        ->scalarNode('uri_factory')->defaultValue('httplug.uri_factory.default')->end()
                        ->scalarNode('stream_factory')->defaultValue('httplug.stream_factory.default')->end()
                    ->end()
                ->end()
                ->arrayNode('classes')
                    ->addDefaultsIfNotSet()
                    ->info('Overwrite a service class instead of using the discovery mechanism.')
                    ->children()
                        ->scalarNode('client')->defaultNull()->end()
                        ->scalarNode('message_factory')->defaultNull()->end()
                        ->scalarNode('uri_factory')->defaultNull()->end()
                        ->scalarNode('stream_factory')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('profiling')
                    ->addDefaultsIfNotSet()
                    ->treatFalseLike(['enabled' => false])
                    ->treatTrueLike(['enabled' => true])
                    ->treatNullLike(['enabled' => $this->debug])
                    ->info('Extend the debug profiler with information about requests.')
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Turn the toolbar on or off. Defaults to kernel debug mode.')
                            ->defaultValue($this->debug)
                        ->end()
                        ->scalarNode('formatter')->defaultNull()->end()
                        ->integerNode('captured_body_length')
                            ->defaultValue(0)
                            ->info('Limit long HTTP message bodies to x characters. If set to 0 we do not read the message body. Only available with the default formatter (FullHttpMessageFormatter).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('discovery')
                    ->addDefaultsIfNotSet()
                    ->info('Control what clients should be found by the discovery.')
                    ->children()
                        ->scalarNode('client')
                            ->defaultValue('auto')
                            ->info('Set to "auto" to see auto discovered client in the web profiler. If provided a service id for a client then this client will be found by auto discovery.')
                        ->end()
                        ->scalarNode('async_client')
                            ->defaultNull()
                            ->info('Set to "auto" to see auto discovered client in the web profiler. If provided a service id for a client then this client will be found by auto discovery.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function configureClients(ArrayNodeDefinition $root)
    {
        $root->children()
            ->arrayNode('clients')
                ->useAttributeAsKey('name')
                ->prototype('array')
                ->fixXmlConfig('plugin')
                ->validate()
                    ->ifTrue(function ($config) {
                        // Make sure we only allow one of these to be true
                        return (bool) $config['flexible_client'] + (bool) $config['http_methods_client'] + (bool) $config['batch_client'] >= 2;
                    })
                    ->thenInvalid('A http client can\'t be decorated with several of FlexibleHttpClient, HttpMethodsClient and BatchClient. Only one of the following options can be true. ("flexible_client", "http_methods_client", "batch_client")')
                ->end()
                ->children()
                    ->scalarNode('factory')
                        ->isRequired()
                        ->cannotBeEmpty()
                        ->info('The service id of a factory to use when creating the adapter.')
                    ->end()
                    ->booleanNode('flexible_client')
                        ->defaultFalse()
                        ->info('Set to true to get the client wrapped in a FlexibleHttpClient which emulates async or sync behavior.')
                    ->end()
                    ->booleanNode('http_methods_client')
                        ->defaultFalse()
                        ->info('Set to true to get the client wrapped in a HttpMethodsClient which emulates provides functions for HTTP verbs.')
                    ->end()
                    ->booleanNode('batch_client')
                        ->defaultFalse()
                        ->info('Set to true to get the client wrapped in a BatchClient which allows you to send multiple request at the same time.')
                    ->end()
                    ->variableNode('config')->defaultValue([])->end()
                    ->append($this->createClientPluginNode())
                ->end()
            ->end()
        ->end();
    }

    /**
     * @param ArrayNodeDefinition $root
     */
    private function configureSharedPlugins(ArrayNodeDefinition $root)
    {
        $pluginsNode = $root
            ->children()
                ->arrayNode('plugins')
                ->info('Global plugin configuration. Plugins need to be explicitly added to clients.')
                ->addDefaultsIfNotSet()
            // don't call end to get the plugins node
        ;
        $this->addSharedPluginNodes($pluginsNode);
    }

    /**
     * Createplugins node of a client.
     *
     * @return ArrayNodeDefinition The plugin node
     */
    private function createClientPluginNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('plugins');

        /** @var ArrayNodeDefinition $pluginList */
        $pluginList = $node
            ->info('A list of plugin service ids and client specific plugin definitions. The order is important.')
            ->prototype('array')
        ;
        $pluginList
            // support having just a service id in the list
            ->beforeNormalization()
                ->always(function ($plugin) {
                    if (is_string($plugin)) {
                        return [
                            'reference' => [
                                'enabled' => true,
                                'id' => $plugin,
                            ],
                        ];
                    }

                    return $plugin;
                })
            ->end()

            ->validate()
                ->always(function ($plugins) {
                    foreach ($plugins as $name => $definition) {
                        if ('authentication' === $name) {
                            if (!count($definition)) {
                                unset($plugins['authentication']);
                            }
                        } elseif (!$definition['enabled']) {
                            unset($plugins[$name]);
                        }
                    }

                    return $plugins;
                })
            ->end()
        ;
        $this->addSharedPluginNodes($pluginList, true);

        $pluginList
            ->children()
                ->arrayNode('reference')
                    ->canBeEnabled()
                    ->info('Reference to a plugin service')
                    ->children()
                        ->scalarNode('id')
                            ->info('Service id of a plugin')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('add_host')
                    ->canBeEnabled()
                    ->addDefaultsIfNotSet()
                    ->info('Set scheme, host and port in the request URI.')
                    ->children()
                        ->scalarNode('host')
                            ->info('Host name including protocol and optionally the port number, e.g. https://api.local:8000')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('replace')
                            ->info('Whether to replace the host if request already specifies one')
                            ->defaultValue(false)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('header_append')
                    ->canBeEnabled()
                    ->info('Append headers to the request. If the header already exists the value will be appended to the current value.')
                    ->fixXmlConfig('header')
                    ->children()
                        ->arrayNode('headers')
                            ->info('Keys are the header names, values the header values')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('header_defaults')
                    ->canBeEnabled()
                    ->info('Set header to default value if it does not exist.')
                    ->fixXmlConfig('header')
                    ->children()
                        ->arrayNode('headers')
                            ->info('Keys are the header names, values the header values')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('header_set')
                    ->canBeEnabled()
                    ->info('Set headers to requests. If the header does not exist it wil be set, if the header already exists it will be replaced.')
                    ->fixXmlConfig('header')
                    ->children()
                        ->arrayNode('headers')
                            ->info('Keys are the header names, values the header values')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('header_remove')
                    ->canBeEnabled()
                    ->info('Remove headers from requests.')
                    ->fixXmlConfig('header')
                    ->children()
                        ->arrayNode('headers')
                            ->info('List of header names to remove')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $node;
    }

    /**
     * Add the definitions for shared plugin configurations.
     *
     * @param ArrayNodeDefinition $pluginNode The node to add to.
     * @param bool                $disableAll Some shared plugins are enabled by default. On the client, all are disabled by default.
     */
    private function addSharedPluginNodes(ArrayNodeDefinition $pluginNode, $disableAll = false)
    {
        $children = $pluginNode->children();

        $children->append($this->createAuthenticationPluginNode());

        $children->arrayNode('cache')
            ->canBeEnabled()
            ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('cache_pool')
                        ->info('This must be a service id to a service implementing Psr\Cache\CacheItemPoolInterface')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                    ->scalarNode('stream_factory')
                        ->info('This must be a service id to a service implementing Http\Message\StreamFactory')
                        ->defaultValue('httplug.stream_factory')
                        ->cannotBeEmpty()
                    ->end()
                    ->arrayNode('config')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('default_ttl')->defaultNull()->end()
                            ->scalarNode('respect_cache_headers')->defaultTrue()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
        // End cache plugin

        $children->arrayNode('cookie')
            ->canBeEnabled()
                ->children()
                    ->scalarNode('cookie_jar')
                        ->info('This must be a service id to a service implementing Http\Message\CookieJar')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                ->end()
            ->end();
        // End cookie plugin

        $decoder = $children->arrayNode('decoder');
        $disableAll ? $decoder->canBeEnabled() : $decoder->canBeDisabled();
        $decoder->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('use_content_encoding')->defaultTrue()->end()
            ->end()
        ->end();
        // End decoder plugin

        $children->arrayNode('history')
            ->canBeEnabled()
                ->children()
                    ->scalarNode('journal')
                        ->info('This must be a service id to a service implementing Http\Client\Plugin\Journal')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                ->end()
            ->end();
        // End history plugin

        $logger = $children->arrayNode('logger');
        $disableAll ? $logger->canBeEnabled() : $logger->canBeDisabled();
        $logger->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('logger')
                    ->info('This must be a service id to a service implementing Psr\Log\LoggerInterface')
                    ->defaultValue('logger')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('formatter')
                    ->info('This must be a service id to a service implementing Http\Message\Formatter')
                    ->defaultNull()
                ->end()
            ->end()
        ->end();
        // End logger plugin

        $redirect = $children->arrayNode('redirect');
        $disableAll ? $redirect->canBeEnabled() : $redirect->canBeDisabled();
        $redirect->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('preserve_header')->defaultTrue()->end()
                ->scalarNode('use_default_for_multiple')->defaultTrue()->end()
            ->end()
        ->end();
        // End redirect plugin

        $retry = $children->arrayNode('retry');
        $disableAll ? $retry->canBeEnabled() : $retry->canBeDisabled();
        $retry->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('retry')->defaultValue(1)->end() // TODO: should be called retries for consistency with the class
            ->end()
        ->end();
        // End retry plugin

        $stopwatch = $children->arrayNode('stopwatch');
        $disableAll ? $stopwatch->canBeEnabled() : $stopwatch->canBeDisabled();
        $stopwatch->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('stopwatch')
                    ->info('This must be a service id to a service extending Symfony\Component\Stopwatch\Stopwatch')
                    ->defaultValue('debug.stopwatch')
                    ->cannotBeEmpty()
                ->end()
            ->end()
        ->end();
        // End stopwatch plugin
    }

    /**
     * Create configuration for authentication plugin.
     *
     * @return NodeDefinition Definition for the authentication node in the plugins list.
     */
    private function createAuthenticationPluginNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('authentication');
        $node
            ->useAttributeAsKey('name')
            ->prototype('array')
                ->validate()
                    ->always()
                    ->then(function ($config) {
                        switch ($config['type']) {
                            case 'basic':
                                $this->validateAuthenticationType(['username', 'password'], $config, 'basic');
                                break;
                            case 'bearer':
                                $this->validateAuthenticationType(['token'], $config, 'bearer');
                                break;
                            case 'service':
                                $this->validateAuthenticationType(['service'], $config, 'service');
                                break;
                            case 'wsse':
                                $this->validateAuthenticationType(['username', 'password'], $config, 'wsse');
                                break;
                        }

                        return $config;
                    })
                ->end()
                ->children()
                    ->enumNode('type')
                        ->values(['basic', 'bearer', 'wsse', 'service'])
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                    ->scalarNode('username')->end()
                    ->scalarNode('password')->end()
                    ->scalarNode('token')->end()
                    ->scalarNode('service')->end()
                    ->end()
                ->end()
            ->end(); // End authentication plugin

        return $node;
    }

    /**
     * Validate that the configuration fragment has the specified keys and none other.
     *
     * @param array  $expected Fields that must exist
     * @param array  $actual   Actual configuration hashmap
     * @param string $authName Name of authentication method for error messages
     *
     * @throws InvalidConfigurationException If $actual does not have exactly the keys specified in $expected (plus 'type')
     */
    private function validateAuthenticationType(array $expected, array $actual, $authName)
    {
        unset($actual['type']);
        $actual = array_keys($actual);
        sort($actual);
        sort($expected);

        if ($expected === $actual) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Authentication "%s" requires %s but got %s',
            $authName,
            implode(', ', $expected),
            implode(', ', $actual)
        ));
    }
}
