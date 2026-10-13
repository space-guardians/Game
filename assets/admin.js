// Point d'entrée du panneau d'administration : Stimulus seul (sans Turbo, qu'EasyAdmin ne gère pas),
// avec les contrôleurs propres au panneau.
import { Application } from '@hotwired/stimulus';
import ChartController from '@symfony/ux-chartjs/controller.js';
import ConfirmController from './admin/controllers/confirm_controller.js';
import GenerationStatusController from './admin/controllers/generation_status_controller.js';

const application = Application.start();
application.register('confirm', ConfirmController);
application.register('generation-status', GenerationStatusController);
// Graphiques de progression de la fiche joueur (Symfony UX Chart.js)
application.register('symfony--ux-chartjs--chart', ChartController);
