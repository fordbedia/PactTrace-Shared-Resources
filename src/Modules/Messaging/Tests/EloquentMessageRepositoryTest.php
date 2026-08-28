<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The Eloquent adapter behind MessageRepository — modeled on
 * EloquentFolderRepository, so the value of covering it is the contract:
 * a thread is opened once per (provider, client, matter) and its subject is
 * never overwritten afterwards; the matter message list is provider-scoped
 * and oldest-first.
 */
class EloquentMessageRepositoryTest extends BaseTest
{
    private MessageRepository $repository;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(MessageRepository::class);
        $this->tenant = ProviderTenantScenario::make('msg-repo');
    }

    public function test_first_or_create_thread_opens_one_thread_and_reuses_it(): void
    {
        $first = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            null,
            'Trust amendment',
        );

        $second = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            null,
            'A different subject entirely',
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MessageThread::query()->count());
        // Subject is applied on creation only — the second call must not
        // overwrite it.
        $this->assertSame('Trust amendment', $second->refresh()->subject);
    }

    public function test_threads_are_distinct_per_matter_scope(): void
    {
        $noMatter = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            null,
            null,
        );

        $onMatter = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            $this->tenant['matter']->id,
            null,
        );

        $this->assertFalse($noMatter->is($onMatter));
        $this->assertSame(2, MessageThread::query()->count());
    }

    public function test_create_message_and_attachment_persist_against_the_thread(): void
    {
        $thread = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            null,
            null,
        );

        $message = $this->repository->createMessage(
            $thread->id,
            $this->tenant['owner']->id,
            'Here is the draft.',
        );

        $attachment = $this->repository->createAttachment(
            $message->id,
            'draft.pdf',
            'message-attachments/1/abc-draft.pdf',
        );

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'thread_id' => $thread->id,
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'Here is the draft.',
        ]);
        $this->assertDatabaseHas('message_attachments', [
            'id' => $attachment->id,
            'message_id' => $message->id,
            'file_name' => 'draft.pdf',
            's3_path' => 'message-attachments/1/abc-draft.pdf',
            'document_id' => null,
        ]);
    }

    public function test_messages_for_matter_are_scoped_to_the_provider_and_ordered_oldest_first(): void
    {
        $mineThread = $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            $this->tenant['matter']->id,
            null,
        );

        $older = $this->repository->createMessage($mineThread->id, $this->tenant['owner']->id, 'first');
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $newer = $this->repository->createMessage($mineThread->id, $this->tenant['owner']->id, 'second');

        // A different provider's thread on a matter of the same integer id
        // range must not leak in.
        $otherTenant = ProviderTenantScenario::make('msg-repo-other');
        $otherThread = $this->repository->firstOrCreateThread(
            $otherTenant['provider']->id,
            $otherTenant['client']->id,
            $otherTenant['matter']->id,
            null,
        );
        $this->repository->createMessage($otherThread->id, $otherTenant['owner']->id, 'not mine');

        $results = $this->repository->messagesForMatter(
            $this->tenant['provider']->id,
            $this->tenant['matter']->id,
        );

        $this->assertSame(['first', 'second'], $results->pluck('body')->all());
        $this->assertTrue($results->every(fn (Message $m) => $m->thread_id === $mineThread->id));
    }

    /* ── inbox paging (All / Unread tabs) ─────────────────────────────── */

    public function test_paginate_threads_excludes_archived_and_annotates_unread(): void
    {
        $user = $this->tenant['owner'];
        $client = $this->tenant['clientUser'];

        $withUnread = $this->openThreadWithMessage($client->id);       // unread for the owner
        $allRead = $this->openThreadWithMessage($user->id, matterId: $this->tenant['matter']->id); // sent by owner
        $archived = $this->openThreadWithMessage($client->id, subject: 'archived one');
        $archived->refresh()->archive();

        $page = $this->repository->paginateThreadsForProvider(
            $this->tenant['provider']->id,
            (int) $user->id,
            15,
            null,
        );

        $ids = $page->getCollection()->pluck('id')->all();
        $this->assertContains($withUnread->id, $ids);
        $this->assertContains($allRead->id, $ids);
        $this->assertNotContains($archived->id, $ids, 'archived threads must not appear in the All tab');

        $byId = $page->getCollection()->keyBy('id');
        $this->assertSame(1, (int) $byId[$withUnread->id]->unread_messages_count);
        $this->assertSame(0, (int) $byId[$allRead->id]->unread_messages_count);
    }

    public function test_paginate_unread_threads_and_count_only_see_threads_with_unread_for_the_user(): void
    {
        $user = $this->tenant['owner'];
        $client = $this->tenant['clientUser'];

        $unread = $this->openThreadWithMessage($client->id);
        $this->openThreadWithMessage($user->id, matterId: $this->tenant['matter']->id); // owner's own => read

        $page = $this->repository->paginateUnreadThreadsForProvider(
            $this->tenant['provider']->id,
            (int) $user->id,
            15,
            null,
        );

        $this->assertSame([$unread->id], $page->getCollection()->pluck('id')->all());
        $this->assertSame(1, $this->repository->countUnreadThreadsForProvider(
            $this->tenant['provider']->id,
            (int) $user->id,
        ));

        // Reading it drops it from both.
        $unread->refresh()->markReadFor((int) $user->id);

        $this->assertCount(0, $this->repository->paginateUnreadThreadsForProvider(
            $this->tenant['provider']->id,
            (int) $user->id,
            15,
            null,
        )->getCollection());
        $this->assertSame(0, $this->repository->countUnreadThreadsForProvider(
            $this->tenant['provider']->id,
            (int) $user->id,
        ));
    }

    /**
     * A distinct thread (not firstOrCreate — that keys only on
     * provider/client/matter, so it can't mint several no-matter threads
     * for one client) with a single message from the given sender.
     */
    private function openThreadWithMessage(int $senderId, ?int $matterId = null, ?string $subject = null): MessageThread
    {
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matterId,
            'subject' => $subject,
        ]);

        $this->repository->createMessage($thread->id, $senderId, 'body');

        return $thread;
    }
}
