<?php

namespace App\Controller\Admin;

use App\Entity\NamedLocation;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NamedLocationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NamedLocation::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield NumberField::new('latitude')->setNumDecimals(6);
        yield NumberField::new('longitude')->setNumDecimals(6);
        yield IntegerField::new('radiusMeters')->setHelp('Geofence radius in meters');
        yield TextField::new('wifiSsid')
            ->setRequired(false)
            ->setHelp('Optional — the current-state tool resolves this place instantly from a matching WiFi network before falling back to GPS/geofence');
    }
}
