<?php

namespace App\Controller\Admin;

use App\Entity\Record;
use App\Enum\RecordSource;
use App\Enum\RecordType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Read-only browser over the synced records: the phone is the only writer,
 * so no new/edit/delete actions here.
 */
class RecordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Record::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Record')
            ->setEntityLabelInPlural('Records')
            ->setDefaultSort(['receivedAt' => 'DESC'])
            ->setSearchFields(['recordUid', 'source', 'type']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('source')->setChoices(array_combine(
                array_map(fn(RecordSource $s) => $s->label(), RecordSource::cases()),
                array_map(fn(RecordSource $s) => $s->value, RecordSource::cases())
            )))
            ->add(ChoiceFilter::new('type')->setChoices(array_combine(
                array_map(fn(RecordType $t) => $t->label(), RecordType::cases()),
                array_map(fn(RecordType $t) => $t->value, RecordType::cases())
            )))
            ->add('deleted')
            ->add('startTime')
            ->add('receivedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        //yield TextField::new('recordUid', 'UID');
        yield ChoiceField::new('type')->setChoices(array_combine(
            array_map(fn(RecordType $t) => $t->label(), RecordType::cases()),
            array_map(fn(RecordType $t) => $t->value, RecordType::cases())
        ));
        yield ChoiceField::new('source')->setChoices(array_combine(
            array_map(fn(RecordSource $s) => $s->label(), RecordSource::cases()),
            array_map(fn(RecordSource $s) => $s->value, RecordSource::cases())
        ));
        yield DateTimeField::new('startTime')->setTimezone('Europe/Sofia');
        yield DateTimeField::new('endTime')->setTimezone('Europe/Sofia')->hideOnIndex();
        yield DateTimeField::new('ingestedAt')->setTimezone('Europe/Sofia')->hideOnIndex();
        yield DateTimeField::new('receivedAt')->setTimezone('Europe/Sofia');
        //yield BooleanField::new('deleted');
        yield TextareaField::new('payloadPretty', 'Payload')
            ->onlyOnDetail()
            ->setFormTypeOption('disabled', true)
            ->setFormTypeOption('attr', ['rows' => 20, 'style' => 'font-family: monospace;']);
    }
}
