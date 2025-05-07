<?php

class BankStatementQuestions {
    public static function getQuestions(): array {
        return [
            '_section_business_info' => 'Business Information',
            'business_name' => 'What is the business name on the account? Answer only with the business name.',
            'other_accounts' => 'Are there any other bank accounts listed on the statement for this business account? If yes, list only account number and sort code. If not, leave empty.',
            'person_name' => 'Is there a name of the person associated to the business account listed? If yes, the answer should only be the name of the person. If not, leave empty.',
            'period_covered' => 'What is the period covered by the bank statement?',
            
            '_section_transaction_analysis' => 'Transaction Analysis',
            'unpaid_transactions' => 'In how many transactions in this statement description mentions unpaid or returned? Answer with a table with columns Number of transactions and total value. If there are none, answer with 0.',
            'hmrc_transactions' => 'Create a table where you can categorise transactions where HMRC is mentioned. Answer with a table with following columns: Date, value, category (for example VAT, PAYE, NDDS).',
            'credit_from_owner' => 'First find out if there is a name of the person associated to this business account. If yes, take that name and find any credit transactions from this person. Answer with a table with columns Number of transactions and total value.',
            'debit_to_owner' => 'First find out if there is a name of the person associated to this business account. If yes, take that name and find any debit transactions from this person. Answer with a table with columns Number of transactions and total value.',
            
            '_section_loan_analysis' => 'Loan Analysis',
            'loan_debit' => 'Create a table where you can show all DEBIT transactions where following keywords are mentioned: loan, FC, Iwoca, etc. Answer with a table with following columns: Date, value, Lender',
            'loan_credit' => 'Create a table where you can show all CREDIT transactions where following keywords are mentioned: loan, FC, Iwoca, etc. Answer with a table with following columns: Date, value, Lender',
            'first_dd' => 'Create a table where you can show all DEBIT transactions indicating first payment of a direct debit. Answer with a table with following columns: Date, value, Payee',
            
            '_section_overdraft_analysis' => 'Overdraft Analysis',
            'overdraft_limit' => 'What is the arranged overdraft limit for this business account? Answer with a pound value.',
            'overdraft_exceeded' => 'On how many days was the overdraft limit exceeded? Answer only with a number.',
            
            '_section_balance_analysis' => 'Balance Analysis',
            'avg_daily_balance' => 'Calculate average daily balance for this statement using this formula: the total of all the end of day balances for the period covered by the bank statement divided by the number of end of day balances on the bank statement. Answer with a pound value only.',
            'total_credit' => 'Calculate total credit transactions excluding the ones mentioning loan, FC, Iwoca or account transfers. Answer with a pound value only.',
            'total_debit' => 'First find out if there is a name of the person associated to this business account. If yes, take that name and find any debit transactions from this person. Then calculate total debit transactions excluding the ones associated to the person owning this business account or account transfers. Answer with a pound value only.',
            
            '_section_recurring_transactions' => 'Recurring Transactions',
            'recurring_credit' => 'Find out if there are any recurring credit transactions. Answer with a table where each vendor will be one row and value will be total for the vendor for all transactions with the columns Vendor and Value.',
            'recurring_debit' => 'Find out if there are any recurring debit transactions. Answer with a table where each vendor will be one row and value will be total for the vendor for all transactions with the columns Vendor and Value.'
        ];
    }
} 