<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connectionName())->create($this->tableName(), function (Blueprint $table): void {
            // A message is handled once per consumer: two consumers of one message do not interfere.
            $table->string('consumer');
            // Not a uuid column: messages from other systems may use any id format.
            $table->string('message_id');
            // Unix time in milliseconds.
            $table->unsignedBigInteger('processed_at')->index();

            $table->primary(['consumer', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->tableName());
    }

    private function connectionName(): ?string
    {
        $connection = config('outbox.inbox.connection') ?? config('outbox.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function tableName(): string
    {
        $table = config('outbox.inbox_table');

        return is_string($table) && $table !== '' ? $table : 'inbox_messages';
    }
};
