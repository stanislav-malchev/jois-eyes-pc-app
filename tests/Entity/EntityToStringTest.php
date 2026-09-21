<?php

namespace App\Tests\Entity;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\Transaction;
use PHPUnit\Framework\TestCase;

class EntityToStringTest extends TestCase
{
    public function testAccountToString()
    {
        $account = new Account();
        $account->setName('DSK Текущ');
        $this->assertEquals('DSK Текущ', (string)$account);
    }

    public function testCategoryToString()
    {
        $category = new Category();
        $category->setName('Groceries');
        $this->assertEquals('Groceries', (string)$category);
    }

    public function testProductToString()
    {
        $product = new Product();
        $product->setName('Milk');
        $this->assertEquals('Milk', (string)$product);
    }

    public function testTransactionToString()
    {
        $tx = new Transaction();
        $tx->setDescription('Coffee purchase');
        $this->assertEquals('Coffee purchase', (string)$tx);
    }

    public function testReceiptToString()
    {
        $receipt = new Receipt();
        $receipt->setMerchant('Billa');
        $this->assertEquals('Billa', (string)$receipt);
    }
}
