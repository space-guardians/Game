// Point d'entrée du panneau d'administration : Stimulus seul (sans Turbo, qu'EasyAdmin ne gère pas),
// avec les contrôleurs propres au panneau.
import { Application } from '@hotwired/stimulus';
import ConfirmController from './admin/controllers/confirm_controller.js';
import GenerationStatusController from './admin/controllers/generation_status_controller.js';

const application = Application.start();
application.register('confirm', ConfirmController);
application.register('generation-status', GenerationStatusController);
