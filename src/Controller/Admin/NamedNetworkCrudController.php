<?php

namespace App\Controller\Admin;

use App\Entity\NamedNetwork;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NamedNetworkCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NamedNetwork::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name')->setHelp('e.g. Bedroom, Car, Living Room');
        yield TextField::new('ssid');
        yield TextareaField::new('notes')->setRequired(false);
    }
}
