<?php

function getBalances(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT
            (SELECT COALESCE(SUM(amount),0) FROM income   WHERE user_id = ?) AS total_income,
            (SELECT COALESCE(SUM(amount),0) FROM savings  WHERE user_id = ?) AS total_saved,
            (SELECT COALESCE(SUM(amount),0) FROM savings  WHERE user_id = ? AND source = 'income')    AS saved_from_income,
            (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND paid_from = 'income')  AS spent_from_income,
            (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND paid_from = 'savings') AS spent_from_savings,
            (SELECT COALESCE(-SUM(amount),0) FROM savings WHERE user_id = ? AND source = 'budget')    AS covered_by_savings"
    );
    $stmt->bind_param('iiiiii', $userId, $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();

    $totalIncome       = (float) $r['total_income'];
    $totalSaved        = (float) $r['total_saved'];
    $savedFromIncome   = (float) $r['saved_from_income'];
    $spentFromIncome   = (float) $r['spent_from_income'];
    $spentFromSavings  = (float) $r['spent_from_savings'];
    $coveredBySavings  = (float) $r['covered_by_savings'];

    $remainingIncome = $totalIncome - $savedFromIncome - $spentFromIncome + $coveredBySavings;
    $savingsBalance  = $totalSaved - $spentFromSavings;

    return [
        'total_income'       => round($totalIncome, 2),
        'remaining_income'   => round($remainingIncome, 2),
        'total_saved'        => round($totalSaved, 2),
        'saved_from_income'  => round($savedFromIncome, 2),
        'saved_direct'       => round($totalSaved - $savedFromIncome + $coveredBySavings, 2),
        'savings_balance'    => round($savingsBalance, 2),
        'spent_from_income'  => round($spentFromIncome, 2),
        'spent_from_savings' => round($spentFromSavings, 2),
        'total_expenses'     => round($spentFromIncome + $spentFromSavings, 2),
        'available_budget'   => round($remainingIncome + $savingsBalance, 2),
    ];
}

function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}