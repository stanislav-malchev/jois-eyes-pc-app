<?php

namespace App\Service\Finance;

use App\Entity\Category;
use App\Entity\LineItem;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

class ProductLinker
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Links a LineItem to a Product, creating the Product if it doesn't exist.
     */
    public function linkLineItem(LineItem $lineItem): void
    {
        if ($lineItem->getProduct() !== null) {
            return;
        }

        $description = $lineItem->getDescription();
        if (!$description) {
            return;
        }

        // Try to find an existing product by name (exact match for now)
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $description]);

        if (!$product) {
            $product = new Product();
            $product->setName($description);
            $product->setDefaultUnit($lineItem->getUnit());

            // Auto-categorization based on category_hint from OCR
            if ($lineItem->getCategoryHint()) {
                $category = $this->getOrCreateCategory($lineItem->getCategoryHint());
                $product->setCategory($category);
            }

            $product->setFirstSeen($lineItem->getReceipt()->getDate());
            $this->entityManager->persist($product);
        }

        $lineItem->setProduct($product);
        $product->setLastSeen($lineItem->getReceipt()->getDate());

        // Update product stats
        $this->updateProductStats($product);
    }

    private function getOrCreateCategory(string $name): Category
    {
        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => $name]);
        if (!$category) {
            $category = new Category();
            $category->setName($name);
            $this->entityManager->persist($category);
            $this->entityManager->flush(); // Flush to make it available for other products in the same run
        }
        return $category;
    }

    private function updateProductStats(Product $product): void
    {
        $lineItems = $this->entityManager->getRepository(LineItem::class)->findBy(['product' => $product]);

        $totalQty = 0;
        $totalSpend = 0;

        foreach ($lineItems as $item) {
            $totalQty += (float)$item->getQuantity();
            $totalSpend += (float)$item->getTotalBgn();
        }

        $product->setLifetimeQuantity((string)$totalQty);
        $product->setLifetimeSpendBgn((string)$totalSpend);

        if ($totalQty > 0) {
            // Simple average for now
            $product->setTypicalPriceBgn((string)($totalSpend / $totalQty));
        }
    }

    /**
     * Links all unlinked LineItems.
     */
    public function linkAllUnlinked(): int
    {
        $lineItems = $this->entityManager->getRepository(LineItem::class)->findBy(['product' => null]);
        $count = 0;
        foreach ($lineItems as $lineItem) {
            $this->linkLineItem($lineItem);
            $count++;
        }
        $this->entityManager->flush();
        return $count;
    }
}
