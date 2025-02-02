<?php

namespace FrockDev\ToolsForLaravel\Swow\ProcessManagement;
use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\DeliverPolicy;
use FrockDev\ToolsForLaravel\Annotations\DisableSpatieValidation;
use FrockDev\ToolsForLaravel\Annotations\NatsJetstream;
use FrockDev\ToolsForLaravel\AnnotationsCollector\Collector;
use FrockDev\ToolsForLaravel\AnnotationsObjectModels\Annotation;
use FrockDev\ToolsForLaravel\FeatureFlags\EndpointFeatureFlagManager;
use FrockDev\ToolsForLaravel\Swow\Processes\AbstractProcess;
use FrockDev\ToolsForLaravel\Swow\Processes\NatsJetStreamConsumerProcess;
use FrockDev\ToolsForLaravel\Swow\Processes\ProcessesRegistry;
use Illuminate\Support\Facades\Log;

class NatsJetstreamProcessManager {

    private Collector $collector;
    private EndpointFeatureFlagManager $endpointFeatureFlagManager;

    public function __construct(Collector                  $collector,
                                EndpointFeatureFlagManager $endpointFeatureFlagManager
    )
    {
        $this->collector = $collector;
        $this->endpointFeatureFlagManager = $endpointFeatureFlagManager;
    }

    public function registerProcesses() {
        Log::info('Registering Nats Jetstream processes');
        $classes = $this->collector->getClassesByAnnotation(NatsJetstream::class);

        foreach ($classes as $endpointClassName => $classAttributesInfo) {
            if (!$this->endpointFeatureFlagManager->checkIfEndpointEnabled($endpointClassName)) {
                continue;
            }
            if (array_key_exists(DisableSpatieValidation::class, $classAttributesInfo['classAnnotations'])) {
                $disableSpatieValidation = true;
            } else {
                $disableSpatieValidation = false;
            }
            /**
             * @var string $attributeClassName
             * @var Annotation $attributeInfo
             */
            foreach ($classAttributesInfo['classAnnotations'] as $attributeClassName => $attributeInfo) {
                if ($attributeClassName !== NatsJetstream::class) {
                    continue;
                }
                /** @var NatsJetstream $attributeExemplar */
                $attributeExemplar = new $attributeClassName(...$attributeInfo->getArguments());
                Log::info('Registering process: '.$attributeExemplar->name.'-'.$attributeExemplar->subject.'-'.$attributeExemplar->streamName);
                /** @var AbstractProcess $process */
                $process = $this->createProcess(
                    app()->make($endpointClassName),
                    $attributeExemplar->subject,
                    $attributeExemplar->streamName,
                    $attributeExemplar->periodInMicroseconds,
                    $disableSpatieValidation,
                    $attributeExemplar->deliverPolicy ?? DeliverPolicy::NEW,
                    $attributeExemplar->ackPolicy ?? AckPolicy::NONE
                );
                $process->setName($attributeExemplar->name . '-' . $attributeExemplar->subject.'-'.$attributeExemplar->subject);
                ProcessesRegistry::register($process);
            }
        }
    }

    private function createProcess(object $consumer, string $subject, string $stream, ?int $periodInMicroseconds=null, bool $disableSpatieValidation=false, $deliverPolicy = DeliverPolicy::NEW, $ackPolicy=AckPolicy::NONE): AbstractProcess
    {
        Log::info('Constructing process: '.$subject.'-'.$stream.'-'.$stream);
        return new NatsJetStreamConsumerProcess($consumer, $subject, $stream, $periodInMicroseconds, $disableSpatieValidation, $deliverPolicy, $ackPolicy);
    }

}
