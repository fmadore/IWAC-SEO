<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class PingQueueFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): PingQueue
    {
        return new PingQueue(
            $container->get(SettingsGate::class),
            $container->get('Omeka\Job\Dispatcher'),
        );
    }
}
