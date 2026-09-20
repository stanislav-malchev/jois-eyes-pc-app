<?php

namespace App\Service\Finance;

use App\Entity\LineItem;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ReceiptOcrProcessor
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Ingests OCR data for a receipt.
     *
     * Expected format:
     * {
     *   "merchant": "Lidl",
     *   "date": "2026-09-20",
     *   "total_bgn": 42.50,
     *   "tax_bgn": 7.08,
     *   "currency": "BGN",
     *   "ocr_source": "path/to/image.jpg",
     *   "ocr_model": "surya",
     *   "ocr_confidence": 0.98,
     *   "raw_text": "...",
     *   "items": [
     *     {
     *       "description": "САПУН ТЕЧЕН 300МЛ",
     *       "quantity": 1,
     *       "unit": "pcs",
     *       "unit_price": 4.50,
     *       "total": 4.50,
     *       "category_hint": "Тоалетни принадлежности"
     *     }
     *   ]
     * }
     */
    public function processOcrData(array $data): Receipt
    {
        $receipt = new Receipt();
        $receipt->setMerchant($data['merchant'] ?? null);

        if (isset($data['date'])) {
            $receipt->setDate(new \DateTimeImmutable($data['date']));
        }

        $receipt->setTotalBgn((string)($data['total_bgn'] ?? 0));
        $receipt->setTaxBgn((string)($data['tax_bgn'] ?? 0));
        $receipt->setCurrency($data['currency'] ?? 'BGN');
        $receipt->setOcrSource($data['ocr_source'] ?? null);
        $receipt->setOcrRawText($data['raw_text'] ?? null);
        $receipt->setOcrModel($data['ocr_model'] ?? null);
        $receipt->setOcrConfidence((string)($data['ocr_confidence'] ?? 0));
        $receipt->setStatus('unmatched');

        $this->entityManager->persist($receipt);

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $itemData) {
                $lineItem = new LineItem();
                $lineItem->setReceipt($receipt);
                $lineItem->setDescription($itemData['description'] ?? 'Unknown');
                $lineItem->setQuantity((string)($itemData['quantity'] ?? 1));
                $lineItem->setUnit($itemData['unit'] ?? 'pcs');
                $lineItem->setUnitPriceBgn((string)($itemData['unit_price'] ?? 0));
                $lineItem->setTotalBgn((string)($itemData['total'] ?? 0));
                $lineItem->setCategoryHint($itemData['category_hint'] ?? null);

                $this->entityManager->persist($lineItem);
            }
        }

        return $receipt;
    }
}
