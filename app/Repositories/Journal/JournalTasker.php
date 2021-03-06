<?php
/**
 * JournalTasker.php
 * Copyright (C) 2016 thegrumpydictator@gmail.com
 *
 * This software may be modified and distributed under the terms of the
 * Creative Commons Attribution-ShareAlike 4.0 International License.
 *
 * See the LICENSE file for details.
 */

declare(strict_types = 1);

namespace FireflyIII\Repositories\Journal;

use Carbon\Carbon;
use Crypt;
use DB;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Class JournalTasker
 *
 * @package FireflyIII\Repositories\Journal
 */
class JournalTasker implements JournalTaskerInterface
{

    /** @var User */
    private $user;

    /**
     * JournalRepository constructor.
     *
     * @param User $user
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Returns a page of a specific type(s) of journal.
     *
     * @param array $types
     * @param int   $page
     * @param int   $pageSize
     *
     * @return LengthAwarePaginator
     */
    public function getJournals(array $types, int $page, int $pageSize = 50): LengthAwarePaginator
    {
        $offset = ($page - 1) * $pageSize;
        $query  = $this->user->transactionJournals()->expanded()->sortCorrectly();
        $query->where('transaction_journals.completed', 1);
        if (count($types) > 0) {
            $query->transactionTypes($types);
        }
        $count    = $this->user->transactionJournals()->transactionTypes($types)->count();
        $set      = $query->take($pageSize)->offset($offset)->get(TransactionJournal::queryFields());
        $journals = new LengthAwarePaginator($set, $count, $pageSize, $page);

        return $journals;
    }

    /**
     * Returns a collection of ALL journals, given a specific account and a date range.
     *
     * @param Collection $accounts
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return Collection
     */
    public function getJournalsInRange(Collection $accounts, Carbon $start, Carbon $end): Collection
    {
        $query = $this->user->transactionJournals()->expanded()->sortCorrectly();
        $query->where('transaction_journals.completed', 1);
        $query->before($end);
        $query->after($start);

        if ($accounts->count() > 0) {
            $ids = $accounts->pluck('id')->toArray();
            // join source and destination:
            $query->leftJoin(
                'transactions as source', function (JoinClause $join) {
                $join->on('source.transaction_journal_id', '=', 'transaction_journals.id')->where('source.amount', '<', 0);
            }
            );
            $query->leftJoin(
                'transactions as destination', function (JoinClause $join) {
                $join->on('destination.transaction_journal_id', '=', 'transaction_journals.id')->where('destination.amount', '>', 0);
            }
            );

            $query->where(
                function (Builder $q) use ($ids) {
                    $q->whereIn('destination.account_id', $ids);
                    $q->orWhereIn('source.account_id', $ids);
                }
            );
        }

        $set = $query->get(TransactionJournal::queryFields());

        return $set;
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return Collection
     */
    public function getPiggyBankEvents(TransactionJournal $journal): Collection
    {
        /** @var Collection $set */
        $events = $journal->piggyBankEvents()->get();
        $events->each(
            function (PiggyBankEvent $event) {
                $event->piggyBank = $event->piggyBank()->withTrashed()->first();
            }
        );

        return $events;
    }

    /**
     * Get an overview of the transactions of a journal, tailored to the view
     * that shows a transaction (transaction/show/xx).
     *
     * @param TransactionJournal $journal
     *
     * @return array
     */
    public function getTransactionsOverview(TransactionJournal $journal): array
    {
        // get all transaction data + the opposite site in one list.
        /**
         * select
         *
         * source.id,
         * source.account_id,
         * source_accounts.name as account_name,
         * source_accounts.encrypted as account_encrypted,
         * source.amount,
         * source.description,
         *
         * destination.id as destination_id,
         * destination.account_id as destination_account_id,
         * destination_accounts.name as destination_account_name,
         * destination_accounts.encrypted as destination_account_encrypted
         *
         *
         * from transactions as source
         *
         * left join transactions as destination ON source.transaction_journal_id =
         * destination.transaction_journal_id AND source.amount = destination.amount * -1 AND source.identifier = destination.identifier
         * -- left join source account name:
         * left join accounts as source_accounts ON source.account_id = source_accounts.id
         * left join accounts as destination_accounts ON destination.account_id = destination_accounts.id
         *
         * where source.transaction_journal_id = 6600
         * and source.amount < 0
         * and source.deleted_at is null
         */
        $set = $journal
            ->transactions()// "source"
            ->leftJoin(
                'transactions as destination', function (JoinClause $join) {
                $join
                    ->on('transactions.transaction_journal_id', '=', 'destination.transaction_journal_id')
                    ->where('transactions.amount', '=', DB::raw('destination.amount * -1'))
                    ->where('transactions.identifier', '=', DB::raw('destination.identifier'))
                    ->whereNull('destination.deleted_at');
            }
            )
            ->with(['budgets', 'categories'])
            ->leftJoin('accounts as source_accounts', 'transactions.account_id', '=', 'source_accounts.id')
            ->leftJoin('accounts as destination_accounts', 'destination.account_id', '=', 'destination_accounts.id')
            ->where('transactions.amount', '<', 0)
            ->whereNull('transactions.deleted_at')
            ->get(
                [
                    'transactions.id',
                    'transactions.account_id',
                    'source_accounts.name as account_name',
                    'source_accounts.encrypted as account_encrypted',
                    'transactions.amount',
                    'transactions.description',
                    'destination.id as destination_id',
                    'destination.account_id as destination_account_id',
                    'destination_accounts.name as destination_account_name',
                    'destination_accounts.encrypted as destination_account_encrypted',
                ]
            );

        $transactions = [];

        /** @var Transaction $entry */
        foreach ($set as $entry) {
            $sourceBalance      = $this->getBalance($entry->id);
            $destinationBalance = $this->getBalance($entry->destination_id);
            $budget             = $entry->budgets->first();
            $category           = $entry->categories->first();
            $transaction        = [
                'source_id'     => $entry->id,
                'source_amount' => $entry->amount,

                'description'                => $entry->description,
                'source_account_id'          => $entry->account_id,
                'source_account_name'        => intval($entry->account_encrypted) === 1 ? Crypt::decrypt($entry->account_name) : $entry->account_name,
                'source_account_before'      => $sourceBalance,
                'source_account_after'       => bcadd($sourceBalance, $entry->amount),
                'destination_id'             => $entry->destination_id,
                'destination_amount'         => bcmul($entry->amount, '-1'),
                'destination_account_id'     => $entry->destination_account_id,
                'destination_account_name'   =>
                    intval($entry->destination_account_encrypted) === 1 ? Crypt::decrypt($entry->destination_account_name) : $entry->destination_account_name,
                'destination_account_before' => $destinationBalance,
                'destination_account_after'  => bcadd($destinationBalance, bcmul($entry->amount, '-1')),
                'budget_id'                  => is_null($budget) ? 0 : $budget->id,
                'category'                   => is_null($category) ? '' : $category->name,
            ];


            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * Collect the balance of an account before the given transaction has hit. This is tricky, because
     * the balance does not depend on the transaction itself but the journal it's part of. And of course
     * the order of transactions within the journal. So the query is pretty complex:
     *
     * @param int $transactionId
     *
     * @return string
     */
    private function getBalance(int $transactionId): string
    {
        // find the transaction first:
        $transaction = Transaction::find($transactionId);
        $date        = $transaction->transactionJournal->date->format('Y-m-d');
        $order       = intval($transaction->transactionJournal->order);
        $journalId   = intval($transaction->transaction_journal_id);
        $identifier  = intval($transaction->identifier);

        // go!
        $sum
            = Transaction
            ::leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->where('account_id', $transaction->account_id)
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
            ->where('transactions.id', '!=', $transactionId)
            ->where(
                function (Builder $q1) use ($date, $order, $journalId, $identifier) {
                    $q1->where('transaction_journals.date', '<', $date); // date
                    $q1->orWhere(
                        function (Builder $q2) use ($date, $order) { // function 1
                            $q2->where('transaction_journals.date', $date);
                            $q2->where('transaction_journals.order', '>', $order);
                        }
                    );
                    $q1->orWhere(
                        function (Builder $q3) use ($date, $order, $journalId) { // function 2
                            $q3->where('transaction_journals.date', $date);
                            $q3->where('transaction_journals.order', $order);
                            $q3->where('transaction_journals.id', '<', $journalId);
                        }
                    );
                    $q1->orWhere(
                        function (Builder $q4) use ($date, $order, $journalId, $identifier) { // function 3
                            $q4->where('transaction_journals.date', $date);
                            $q4->where('transaction_journals.order', $order);
                            $q4->where('transaction_journals.id', $journalId);
                            $q4->where('transactions.identifier', '>', $identifier);
                        }
                    );
                }
            )->sum('transactions.amount');

        return strval($sum);
    }
}