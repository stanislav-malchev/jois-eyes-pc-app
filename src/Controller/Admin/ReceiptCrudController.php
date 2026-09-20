<?php

namespace App\Controller\Admin;

use App\Entity\Receipt;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ReceiptCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Receipt::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Receipt')
            ->setEntityLabelInPlural('Receipts')
            ->setDefaultSort(['date' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $linkTransaction = Action::new('linkTransaction', 'Link Transaction', 'fa fa-link')
            ->linkToRoute('admin_finance_link_receipt_transaction', function (Receipt $entity) {
                return ['receiptId' => $entity->getId()];
            });

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $linkTransaction)
            ->add(Crud::PAGE_DETAIL, $linkTransaction);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('date');
        yield TextField::new('merchant');
        yield NumberField::new('totalBgn');
        yield TextField::new('status');
        yield AssociationField::new('transaction');
        yield DateField::new('importedAt')->hideOnForm();
    }
}
