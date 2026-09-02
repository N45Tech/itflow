<?php

/*
 * N45 cross-module transaction lock order.
 *
 * Row locks that cross feature boundaries must be observed through a scope so a
 * future edit cannot quietly invert the order and introduce a deadlock. Named
 * advisory locks are acquired, sorted, before the database transaction and are
 * therefore outside this row-lock sequence.
 */

final class N45LockOrder
{
    private const RANKS = [
        'authorization' => 10,
        'api_key' => 20,
        'client' => 30,
        'project' => 40,
        'settings' => 50,
        'asset' => 60,
        'identity' => 70,
        'automation_incident' => 80,
        'ticket' => 90,
        'task' => 100,
        'agreement' => 110,
        'documentation_obligation' => 120,
        'document' => 130,
        'file_stage' => 140,
        'automation_event' => 150,
        'audit' => 160,
    ];

    private string $operation;
    private int $lastRank = 0;
    private string $lastResource = '';
    private array $lastIds = [];

    public function __construct(string $operation)
    {
        $this->operation = trim($operation) ?: 'database mutation';
    }

    public static function ranks(): array
    {
        return self::RANKS;
    }

    /**
     * Assert the next row-lock class and ascending identifier before issuing a
     * SELECT ... FOR UPDATE or an equivalent mutating statement.
     */
    public function observe(string $resource, int $id = 0): void
    {
        if (!isset(self::RANKS[$resource])) {
            throw new LogicException("Unknown N45 lock-order resource: $resource");
        }

        $rank = self::RANKS[$resource];
        if ($rank < $this->lastRank) {
            throw new LogicException(
                "$this->operation attempted to lock $resource after $this->lastResource"
            );
        }

        $id = max(0, $id);
        if ($rank === $this->lastRank && $id > 0) {
            $lastId = intval($this->lastIds[$resource] ?? 0);
            if ($lastId > 0 && $id < $lastId) {
                throw new LogicException(
                    "$this->operation attempted to lock $resource row $id after row $lastId"
                );
            }
            $this->lastIds[$resource] = $id;
        } elseif ($id > 0) {
            $this->lastIds[$resource] = $id;
        }

        $this->lastRank = $rank;
        $this->lastResource = $resource;
    }
}
