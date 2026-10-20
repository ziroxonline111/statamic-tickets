<?php

namespace KareliaLabs\Tickets;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Orders as JSON files in storage/statamic/karelia-tickets/orders. Writes are
 * atomic (write to a temporary file, then rename), and order numbers come from
 * a counter that is only changed under a lock.
 */
class OrderRepository
{
    private ?array $memory = null;

    public function directory(): string
    {
        return rtrim((string) config('statamic.tickets.path', storage_path('statamic/karelia-tickets')), '/\\');
    }

    /** @return Collection<int, Order> newest first */
    public function all(): Collection
    {
        if ($this->memory === null) {
            $this->memory = [];

            foreach (File::glob($this->directory().'/orders/*.json') ?: [] as $file) {
                $data = json_decode((string) File::get($file), true);

                if (is_array($data) && isset($data['id'])) {
                    $this->memory[$data['id']] = new Order($data);
                }
            }
        }

        return collect($this->memory)->sortByDesc(fn (Order $o) => $o->get('created_at'))->values();
    }

    public function find(string $id): ?Order
    {
        if (! preg_match('/^ord_[a-z0-9]{26}$/', $id)) {
            return null;
        }

        $this->all();

        return $this->memory[$id] ?? null;
    }

    public function findByStripeSession(string $session): ?Order
    {
        return $this->all()->first(fn (Order $o) => $o->get('stripe.session') === $session);
    }

    /** @return Collection<int, Order> */
    public function forEvent(string $eventId): Collection
    {
        return $this->all()->filter(fn (Order $o) => $o->eventId() === $eventId)->values();
    }

    /** @return array{0: Order, 1: array}|null */
    public function findTicket(string $code): ?array
    {
        $code = Str::upper(trim($code));

        foreach ($this->all() as $order) {
            if ($ticket = $order->ticket($code)) {
                return [$order, $ticket];
            }
        }

        return null;
    }

    public function codeExists(string $code): bool
    {
        return $this->findTicket($code) !== null;
    }

    public function save(Order $order): Order
    {
        $directory = $this->directory().'/orders';
        File::ensureDirectoryExists($directory);

        if (! $order->get('number')) {
            $order->set('number', $this->nextNumber());
        }

        $path = $directory.'/'.$order->id().'.json';
        $temp = $path.'.'.Str::random(6).'.tmp';
        File::put($temp, json_encode($order->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temp, $path);

        $this->all();
        $this->memory[$order->id()] = $order;

        return $order;
    }

    /** Forget what was read, e.g. after another process may have written. */
    public function flush(): void
    {
        $this->memory = null;
    }

    private function nextNumber(): string
    {
        $file = $this->directory().'/counter.txt';
        File::ensureDirectoryExists($this->directory());

        $handle = fopen($file, 'c+');
        flock($handle, LOCK_EX);
        $current = (int) trim((string) stream_get_contents($handle)) ?: 1000;
        $next = $current + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $next);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return (string) $next;
    }
}
