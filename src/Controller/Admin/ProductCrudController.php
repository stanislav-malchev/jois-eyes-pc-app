<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product')
            ->setEntityLabelInPlural('Products')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield AssociationField::new('category');
        yield TextField::new('defaultUnit');
        yield NumberField::new('typicalPriceBgn');
        yield NumberField::new('lifetimeQuantity')->hideOnForm();
        yield NumberField::new('lifetimeSpendBgn')->hideOnForm();
        yield DateField::new('firstSeen')->hideOnForm();
        yield DateField::new('lastSeen')->hideOnForm();

        // Sub-list showing purchase history (LineItems)
        // Gathering purchase history via a collection field is one way.
        // We'll need a way to show date, merchant, unit price.
        // LineItem has: receipt (which has date, merchant), unitPriceBgn.
        // EasyAdmin's CollectionField can show related items.
        // However, we might want a more read-only view in Detail page.
        yield CollectionField::new('lineItems', 'Purchase History')
            ->onlyOnDetail()
            ->setTemplatePath('admin/fields/product_history.html.twig');
    }
}
