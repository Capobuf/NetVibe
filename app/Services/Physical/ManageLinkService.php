<?php

declare(strict_types=1);

namespace App\Services\Physical;

use App\Models\Link;
use App\Models\Port;
use Illuminate\Support\Facades\DB;

final class ManageLinkService
{
    /**
     * Connect two ports (idempotent from a caller standpoint). Returns the created Link.
     * Throws \RuntimeException on invalid state or duplicate link.
     */
    public function connect(Port $a, Port $b, ?int $cableTypeId = null): Link
    {
        if ($a->id === $b->id) {
            throw new \RuntimeException('Cannot connect a port to itself');
        }

        // ensure ports are available
        if ($a->status !== 'down' || $b->status !== 'down') {
            throw new \RuntimeException('One or both ports are not available for connection');
        }

        // check duplicates in either direction
        $exists = Link::where(function ($q) use ($a, $b) {
            $q->where('port_a_id', $a->id)->where('port_b_id', $b->id);
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('port_a_id', $b->id)->where('port_b_id', $a->id);
        })->exists();

        if ($exists) {
            throw new \RuntimeException('Link already exists between these ports');
        }

        return DB::transaction(function () use ($a, $b, $cableTypeId) {
            $link = Link::create([
                'port_a_id' => $a->id,
                'port_b_id' => $b->id,
                'cable_type_id' => $cableTypeId,
            ]);

            $a->update(['status' => 'active', 'connected_to_port_id' => $b->id]);
            $b->update(['status' => 'active', 'connected_to_port_id' => $a->id]);

            return $link;
        });
    }

    /**
     * Disconnect a port by deleting the related link and updating statuses.
     */
    public function disconnect(Port $port): void
    {
        $link = Link::where('port_a_id', $port->id)
            ->orWhere('port_b_id', $port->id)
            ->first();

        if (! $link) {
            throw new \RuntimeException('No link found for the given port');
        }

        DB::transaction(function () use ($link, $port) {
            $remotePortId = $link->port_a_id === $port->id ? $link->port_b_id : $link->port_a_id;

            $link->delete();

            $port->update(['status' => 'down', 'connected_to_port_id' => null]);
            Port::where('id', $remotePortId)->update(['status' => 'down', 'connected_to_port_id' => null]);
        });
    }
}
