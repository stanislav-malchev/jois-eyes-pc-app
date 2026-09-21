<?php

namespace App\Controller\Admin;

use App\Entity\Transaction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transaction')
            ->setEntityLabelInPlural('Transactions')
            ->setDefaultSort(['date' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $linkReceipt = Action::new('linkReceipt', 'Link Receipt', 'fa fa-link')
            ->linkToRoute('admin_finance_link_transaction_receipt', function (Transaction $entity) {
                return ['transactionId' => $entity->getId()];
            });

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $linkReceipt)
            ->add(Crud::PAGE_DETAIL, $linkReceipt);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('date')
            ->add('account')
            ->add('currency')
            ->add('softDeleted')
            ->add('transactionType');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('date');
        yield TextField::new('description');
        yield TextField::new('counterparty');
        yield NumberField::new('debitBgn');
        yield NumberField::new('creditBgn');
        yield TextField::new('currency');
        yield NumberField::new('exchangeRate')->hideOnIndex();
        yield BooleanField::new('softDeleted')->hideOnIndex()->hideOnDetail()->hideWhenCreating();
        yield AssociationField::new('account');
    }
}
