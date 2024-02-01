<?php

namespace FrockDev\ToolsForLaravel;

use Basis\Nats\Configuration;
use FrockDev\ToolsForLaravel\AnnotationsCollector\Collector;
use FrockDev\ToolsForLaravel\Console\AddToArrayToGrpcObjects;
use FrockDev\ToolsForLaravel\Console\CollectAttributesToCache;
use FrockDev\ToolsForLaravel\Console\CreateEndpointsFromProto;
use FrockDev\ToolsForLaravel\Console\AddNamespacesToComposerJson;
use FrockDev\ToolsForLaravel\Console\GenerateGrafanaMetrics;
use FrockDev\ToolsForLaravel\Console\PrepareProtoFiles;
use FrockDev\ToolsForLaravel\Console\ResetNamespacesInComposerJson;
use FrockDev\ToolsForLaravel\EventLIsteners\BeforeRequestProcessedListener;
use FrockDev\ToolsForLaravel\Events\BeforeRequestProcessedEvent;
use FrockDev\ToolsForLaravel\InterceptorInterfaces\PostInterceptorInterface;
use FrockDev\ToolsForLaravel\InterceptorInterfaces\PreInterceptorInterface;
use FrockDev\ToolsForLaravel\NatsMessengers\JsonNatsMessenger;
use FrockDev\ToolsForLaravel\NatsMessengers\GrpcNatsMessenger;
use FrockDev\ToolsForLaravel\Swow\Metrics\MetricFactory;
use FrockDev\ToolsForLaravel\Swow\Metrics\MetricFactoryInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Jaeger\Config;
use OpenTracing\NoopTracer;
use OpenTracing\Tracer;
use Prometheus\Storage\InMemory;
use const Jaeger\SAMPLER_TYPE_CONST;

class FrockServiceProvider extends ServiceProvider
{

    private function prepareEndpointsAndInterceptors(Collector $collector)
    {
        foreach (scandir(app_path() . '/Modules') as $module) {
            if ($module === '.' || $module === '..' || !is_dir(app_path() . '/Modules/' . $module)) continue;
            foreach (scandir(app_path() . '/Modules/' . $module . '/Endpoints') as $subService) {
                if ($subService === '.' || $subService === '..' || !is_dir(app_path() . '/Modules/' . $module . '/Endpoints/' . $subService)) continue;

                foreach (scandir(app_path() . '/Modules/' . $module . '/Endpoints/' . $subService) as $version) {
                    if ($version === '.' || $version === '..' || !is_dir(app_path() . '/Modules/' . $module . '/Endpoints/' . $subService . '/' . $version)) continue;

                    foreach (scandir(app_path() . '/Modules/' . $module . '/Endpoints/' . $subService . '/' . $version) as $endpoint) {
                        if ($endpoint === '.' || $endpoint === '..' || !is_file(app_path() . '/Modules/' . $module . '/Endpoints/' . $subService . '/' . $version . '/' . $endpoint)) continue;

                        $endpointClass = 'App\\Modules\\' . $module . '\\Endpoints\\' . $subService . '\\' . $version . '\\' . substr($endpoint, 0, -4);

                        $endpointAttributes = $collector->getAnnotationsByClassName($endpointClass);
                        $this->app->singleton($endpointClass, $endpointClass);
                        $this->app->singleton('\\'.$endpointClass, $endpointClass);
                        $endpointInstance = $this->app->make($endpointClass);
                        if (array_key_exists('methodAnnotations', $endpointAttributes)) {
                            foreach ($endpointAttributes['methodAnnotations'] as $methodAttributes) {
                                foreach ($methodAttributes as $attributeClassName => $attributeInfo) {
                                    $attributeInstance = new $attributeClassName(...$attributeInfo->getArguments());
                                    if ($attributeInstance instanceof PreInterceptorInterface) {
                                        $endpointInstance->addPreInterceptor($attributeInstance);
                                    } elseif ($attributeInstance instanceof PostInterceptorInterface) {
                                        $endpointInstance->addPostInterceptor($attributeInstance);
                                    } else {
                                        unset($attributeInstance);
                                    }
                                }
                            }
                        }
                        $this->app->instance($endpointClass, $endpointInstance);
                        $this->app->instance('\\'.$endpointClass, $endpointInstance);
                    }
                }
            }
        }
    }

    public function register()
    {
        $this->commands(CreateEndpointsFromProto::class);
        $this->commands(AddNamespacesToComposerJson::class);
        $this->commands(ResetNamespacesInComposerJson::class);
        $this->commands(PrepareProtoFiles::class);
        $this->commands(AddToArrayToGrpcObjects::class);
        $this->commands(GenerateGrafanaMetrics::class);
        $this->commands(CollectAttributesToCache::class);

        $this->app->singleton(\Prometheus\CollectorRegistry::class, function() {
            return new \Prometheus\CollectorRegistry(new InMemory());
        });

        $this->app->bind(MetricFactoryInterface::class, MetricFactory::class);

        // own laravel attributes collector
        $collector = new Collector($this->app);
        $collector->collect(app_path());

        $this->prepareEndpointsAndInterceptors($collector);

        //@todo should make tracer async
        $this->app->singleton(Tracer::class, function($app) {
            if (config('jaeger.traceEnabled', false)===true) {
                $config = new Config(
                    [
                        'sampler' => [
                            'type' => config('jaeger.samplerType', SAMPLER_TYPE_CONST),
                            'param' => true,
                        ],
                        'logging' => true,
                        'dispatch_mode' => Config::JAEGER_OVER_BINARY_UDP,
                        "local_agent" => [
                            "reporting_host" => config('jaeger.reportingHost', "jaeger-all-in-one.jaeger"),
                            "reporting_port" => config('jaeger.reportingPort', 6832),
                        ],
                    ],
                    config('app.name'),
                );
                return $config->initializeTracer();
            } else {
                return new NoopTracer();
            }
        });
    }

    public function boot()
    {

        $this->publishes([
            __DIR__.'/../config/frock.php' => config_path('frock.php'),
        ]);

        $this->publishes([
            __DIR__.'/../config/nats.php' => config_path('nats.php'),
        ]);

        $this->publishes([
            __DIR__.'/../config/jaeger.php' => config_path('jaeger.php'),
        ]);
    }
}
