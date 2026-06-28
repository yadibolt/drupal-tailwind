<?php

namespace Drupal\tailwind\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tailwind\Compiler\TailwindCompiler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TailwindCompileSubscriber implements EventSubscriberInterface
{
  public function __construct(
    protected TailwindCompiler $tailwindCompiler,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) return;

    $config = $this->configFactory->get('tailwind.settings');
    if (!$config->get('autocompile')) return;

    if ($this->tailwindCompiler->shouldRecompile()) {
      $this->tailwindCompiler->compile();
    }
  }
}
