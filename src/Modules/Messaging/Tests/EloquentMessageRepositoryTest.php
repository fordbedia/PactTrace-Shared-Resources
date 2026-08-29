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
 * The Eloquent adapter behind MessageRepository. The contract that matters:
 * a thread is opened once per (provider, matter, staff member, subject) and
 * its subject/client are never overwritten afterwards; the matter thread
 * list and matter message list are provider-scoped.
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

    private function open(string $subject, ?int $staffId = null, ?int $matterId = null): MessageThread
    {
        return $this->repository->firstOrCreateThread(
            $this->tenant['provider']->id,
            $matterId ?? $this->tenant['matter']->id,
            $staffId ?? $this->tenant['owner']->id,
            $this->tenant['matter']->client_id,
            $subject,
        );
    }

    public function test_first_or_create_thread_opens_one_thread_and_reuses_it(): void
    {
        $first = $this->open('Trust amendment');
        $again = $this->open('Trust amendment');

        $this->assertTrue($first->is($again));
        $this->assertSame(1, MessageThread::query()->count());
        $this->assertSame($this->tenant['matter']->client_id, (int) $first->client_id);
    }

    public function test_threads_are_distinct_per_subject(): void
    {
        $a = $this->open('Retainer questions');
        $b = $this->open('Document request — W2s');

        $this->assertFalse($a->is($b));
        $this->assertSame(2, MessageThread::query()->count());
    }

    public function test_threads_are_distinct_per_staff_member(): void
    {
        $withOwner = $this->open('Same subject', staffId: $this->tenant['owner']->id);
        $withStaff = $this->open('Same subject', staffId: $this->tenant['staff']->id);

        $this->assertFalse($withOwner->is($withStaff));
        $this->assertSame(2, MessageThread::query()->count());
    }

    public function test_create_message_and_attachment_persist_against_the_thread(): void
    {
        $thread = $this->open('Draft review');

        $message = $this->repository->createMessage($thread->id, $this->tenant['owner']->id, 'Here is the draft.');
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
        $mineThread = $this->open('Mine');

        $older = $this->repository->createMessage($mineThread->id, $this->tenant['owner']->id, 'first');
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $this->repository->createMessage($mineThread->id, $this->tenant['owner']->id, 'second');

        $otherTenant = ProviderTenantScenario::make('msg-repo-other');
        $otherThread = $this->repository->firstOrCreateThread(
            $otherTenant['provider']->id,
            $otherTenant['matter']->id,
            $otherTenant['owner']->id,
            $otherTenant['matter']->client_id,
            'Not mine',
        );
        $this->repository->createMessage($otherThread->id, $otherTenant['owner']->id, 'not mine');

        $results = $this->repository->messagesForMatter(
            $this->tenant['provider']->id,
            $this->tenant['matter']->id,
        );

        $this->assertSame(['first', 'second'], $results->pluck('body')->all());
        $this->assertTrue($results->every(fn (Message $m) => $m->thread_id === $mineThread->id));
    }

    public function test_threads_for_matter_returns_only_this_matters_threads_with_unread_for_the_user(): void
    {
        $user = $this->tenant['owner'];
        $client = $this->tenant['clientUser'];

        $withUnread = $this->open('Has unread');
        $this->repository->createMessage($withUnread->id, $client->id, 'from the client'); // unread for owner

        $allRead = $this->open('All read');
        $this->repository->createMessage($allRead->id, $user->id, 'from the owner');       // sent by owner

        // A thread on a different matter of the same provider must not leak in.
        $this->open('Other matter', matterId: $this->tenant['otherMatter']->id);

        $threads = $this->repository->threadsForMatter(
            $this->tenant['provider']->id,
            $this->tenant['matter']->id,
            (int) $user->id,
        );

        $byId = $threads->keyBy('id');
        $this->assertEqualsCanonicalizing([$withUnread->id, $allRead->id], $byId->keys()->all());
        $this->assertSame(1, (int) $byId[$withUnread->id]->unread_messages_count);
        $this->assertSame(0, (int) $byId[$allRead->id]->unread_messages_count);
    }

    /* ── inbox paging (All / Unread tabs) ─────────────────────────────── */

    public function test_paginate_threads_excludes_archived_and_annotates_unread(): void
    {
        $user = $this->tenant['owner'];
        $client = $this->tenant['clientUser'];

        $withUnread = $this->openThreadWithMessage('unread one', $client->id);
        $allRead = $this->openThreadWithMessage('read one', $user->id);
        $archived = $this->openThreadWithMessage('archived one', $client->id);
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

        $unread = $this->openThreadWithMessage('unread', $client->id);
        $this->openThreadWithMessage('read', $user->id);

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

    private function openThreadWithMessage(string $subject, int $senderId): MessageThread
    {
        $thread = $this->open($subject);
        $this->repository->createMessage($thread->id, $senderId, 'body');

        return $thread;
    }
}
