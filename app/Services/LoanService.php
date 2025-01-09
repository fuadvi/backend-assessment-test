<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService
{
    /**
     * Create a Loan
     *
     * @param  User  $user
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  int  $terms
     * @param  string  $processedAt
     *
     * @return Loan
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
        DB::beginTransaction();
        try {
            // Create the loan
            $loan = Loan::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'terms' => $terms,
                'outstanding_amount' => $amount,
                'processed_at' => Carbon::parse($processedAt),
                'status' => Loan::STATUS_DUE,
            ]);

            // Calculate the repayment amount (rounded down)
            $repaymentAmount = floor($amount / $terms);
            $remainingAmount = $amount - ($repaymentAmount * ($terms - 1)); // This ensures the last repayment matches the total amount

            // Create scheduled repayments using the relationship
            for ($i = 1; $i <= $terms; $i++) {
                $dueDate = Carbon::parse($processedAt)->addMonth($i);

                // Adjust the amount for the last repayment
                $currentAmount = ($i === $terms) ? $remainingAmount : $repaymentAmount;

                //create the scheduled repayment
                $loan->scheduledRepayments()->create([
                    'amount' => $currentAmount,
                    'outstanding_amount' => $currentAmount,
                    'currency_code' => $currencyCode,
                    'due_date' => $dueDate,
                    'status' => ScheduledRepayment::STATUS_DUE,
                ]);
            }
            DB::commit();

            return $loan;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Repay Scheduled Repayments for a Loan
     *
     * @param  Loan  $loan
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  string  $receivedAt
     *
     * @return ReceivedRepayment
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        DB::beginTransaction();

        try {

            // Record the received repayment
            $receivedRepayment = $this->recordReceivedRepayment($loan, $amount, $currencyCode, $receivedAt);

            // Update scheduled repayments
            $this->updateScheduledRepayments($loan, $amount);


            // Update loan's outstanding amount
            $amountReceipt = collect($loan->scheduledRepayments)
                ->where('due_date', '<=', $receivedAt)
                ->sum('amount');

            $amountPartial = collect($loan->scheduledRepayments)
                ->where('status','==','partial')
                ->sum('outstanding_amount');


            // Update the outstanding amount of the loan
            $loan->outstanding_amount = max($loan->outstanding_amount - ($amountReceipt + $amountPartial), 0); // Ensure no negative values

            // Handle cases where rounding may leave an outstanding amount of 1 or small residual amounts
            if (abs($loan->outstanding_amount) <= 1) {
                $loan->outstanding_amount = 0;
            }

            // Check if the loan is fully repaid
            if ($loan->outstanding_amount == 0) {
                $loan->update([
                    'status' => Loan::STATUS_REPAID,
                ]);
            } else {
                $loan->update([
                    'status' => Loan::STATUS_DUE,
                ]);
            }

            $loan->save();

            DB::commit();

            return $receivedRepayment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

    }

    /**
     * Record the received repayment.
     *
     * @param Loan $loan
     * @param int $amount
     * @param string $currencyCode
     * @param string $receivedAt
     * @return ReceivedRepayment
     */
    protected function recordReceivedRepayment(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        return ReceivedRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * Update scheduled repayments based on the received amount.
     *
     * @param Loan $loan
     * @param int $amount
     * @return void
     */
    protected function updateScheduledRepayments(Loan $loan, int $amount): void
    {
        $remainingAmount = $amount;

        // Get all due or partial repayments ordered by due date
        $scheduledRepayments = ScheduledRepayment::where('loan_id', $loan->id)
            ->whereIn('status', [ScheduledRepayment::STATUS_DUE, ScheduledRepayment::STATUS_PARTIAL])
            ->orderBy('due_date', 'asc')
            ->get();

        // Process each scheduled repayment
        foreach ($scheduledRepayments as $repayment) {
            if ($remainingAmount <= 0) {
                break;
            }

            $repaymentOutstanding = $repayment->outstanding_amount;

            if ($remainingAmount >= $repaymentOutstanding) {

                // Fully pay this repayment
                $repayment->outstanding_amount = 0;
                $repayment->status = ScheduledRepayment::STATUS_REPAID;
                $remainingAmount -= $repaymentOutstanding;
            } else {
//                dd( $remainingAmount , $repayment->amount);
                // Partially pay this repayment
                $repayment->outstanding_amount = $remainingAmount;
                $repayment->status = ScheduledRepayment::STATUS_PARTIAL;
                $remainingAmount = 0;
            }

            $repayment->save();
        }
    }
}
