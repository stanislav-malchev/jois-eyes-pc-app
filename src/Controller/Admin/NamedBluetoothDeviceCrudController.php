<?php

namespace App\Controller\Admin;

use App\Entity\NamedBluetoothDevice;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NamedBluetoothDeviceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NamedBluetoothDevice::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name')->setHelp('e.g. Car, Living Room Speaker');
        yield TextField::new('deviceName')
            ->setRequired(false)
            ->setHelp('The Bluetooth device\'s broadcast name, if known');
        yield TextField::new('macAddress')
            ->setRequired(false)
            ->setHelp('The Bluetooth device\'s MAC address, if known (e.g. AA:BB:CC:DD:EE:FF)');
        yield TextareaField::new('notes')->setRequired(false);
    }
}
