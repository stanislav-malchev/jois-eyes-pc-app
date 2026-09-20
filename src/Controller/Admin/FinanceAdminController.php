<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\Transaction;
use App\Service\Finance\ReceiptTransactionLinker;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/finance')]
class FinanceAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUrlGenerator $adminUrlGenerator,
        private ReceiptTransactionLinker $linker
    ) {
    }

    #[Route('/link-receipt/{transactionId}', name: 'admin_finance_link_transaction_receipt')]
    public function linkTransactionReceipt(string $transactionId, Request $request): Response
    {
        $transaction = $this->entityManager->getRepository(Transaction::class)->find($transactionId);
        if (!$transaction) {
            $this->addFlash('danger', 'Transaction not found.');
            return $this->redirect($this->adminUrlGenerator->setController(TransactionCrudController::class)->setAction(Action::INDEX)->generateUrl());
        }

        // For now, let's just trigger the auto-linker for this specific transaction's amount/date if possible,
        // or just show a list of receipts to link.
        // The objective says "trigger manual linking".
        // A simple "manual linking" could be just redirecting to a filtered Receipt list,
        // but let's try to link it automatically first if possible.

        $receipts = $this->entityManager->getRepository(Receipt::class)->findBy([
            'date' => $transaction->getDate(),
            'totalBgn' => $transaction->getDebitBgn(),
            'transaction' => null
        ]);

        if (count($receipts) === 1) {
            $receipt = $receipts[0];
            $receipt->setTransaction($transaction);
            $receipt->setStatus('matched_to_transaction');
            $this->entityManager->flush();
            $this->addFlash('success', 'Receipt linked successfully!');
        } else {
            $this->addFlash('warning', 'Found ' . count($receipts) . ' potential receipts. Manual selection not implemented yet, showing all receipts.');
            return $this->redirect($this->adminUrlGenerator
                ->setController(ReceiptCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters[transaction]', null)
                ->generateUrl());
        }

        return $this->redirect($this->adminUrlGenerator->setController(TransactionCrudController::class)->setAction(Action::INDEX)->generateUrl());
    }

    #[Route('/link-transaction/{receiptId}', name: 'admin_finance_link_receipt_transaction')]
    public function linkReceiptTransaction(string $receiptId, Request $request): Response
    {
        $receipt = $this->entityManager->getRepository(Receipt::class)->find($receiptId);
        if (!$receipt) {
            $this->addFlash('danger', 'Receipt not found.');
            return $this->redirect($this->adminUrlGenerator->setController(ReceiptCrudController::class)->setAction(Action::INDEX)->generateUrl());
        }

        if ($this->linker->linkReceipt($receipt)) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Receipt linked successfully!');
        } else {
            $this->addFlash('warning', 'Could not find a unique matching transaction.');
        }

        return $this->redirect($this->adminUrlGenerator->setController(ReceiptCrudController::class)->setAction(Action::INDEX)->generateUrl());
    }
}
