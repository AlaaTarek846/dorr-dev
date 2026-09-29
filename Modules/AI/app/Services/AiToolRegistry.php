<?php

namespace Modules\AI\Services;

use Modules\AI\Contracts\AiToolInterface;

/**
 * Resolves the tool classes listed in config('ai.tools.registry') through
 * the container (so a tool's own constructor dependencies, like
 * AiUsageStatusTool's AiChatUsageGuard, are wired automatically) and
 * indexes them by AiToolInterface::name().
 */
class AiToolRegistry
{
    /**
     * @var array<string, AiToolInterface>|null
     */
    protected ?array $tools = null;

    /**
     * @return array<string, AiToolInterface>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];

        foreach (config('ai.tools.registry', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $tool = app($class);

            if (! $tool instanceof AiToolInterface) {
                continue;
            }

            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }

    public function find(string $name): ?AiToolInterface
    {
        return $this->all()[$name] ?? null;
    }
}
