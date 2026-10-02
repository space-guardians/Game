<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Lien « Historique » sur la fiche d'une entité auditée, vers ses modifications (§5.6.3).
 */
trait HistoryActionTrait
{
    /** @param string $entityKey nom de la table de l'entité */
    private function addHistoryAction(Actions $actions, string $entityKey): Actions
    {
        $history = Action::new('history', 'Historique')
            ->linkToUrl(fn(object $entity): string => $this->generateUrl('admin_entity_history_list', [
                'entity' => $entityKey,
                'objet' => (string) $entity->getId(), // @phpstan-ignore method.notFound (entités à identifiant « id »)
            ]));

        return $actions
            ->add(Crud::PAGE_DETAIL, $history)
            ->setPermission('history', AdminRole::Admin->value);
    }
}
