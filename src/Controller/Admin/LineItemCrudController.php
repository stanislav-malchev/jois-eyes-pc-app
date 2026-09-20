<?php

namespace App\Controller\Admin;

use App\Entity\LineItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LineItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LineItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Line Item')
            ->setEntityLabelInPlural('Line Items');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('description');
        yield NumberField::new('quantity');
        yield TextField::new('unit');
        yield NumberField::new('unitPriceBgn');
        yield NumberField::new('totalBgn');
        yield AssociationField::new('product');
        yield AssociationField::new('receipt');
    }
}
