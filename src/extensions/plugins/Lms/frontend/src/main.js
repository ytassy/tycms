import { registerRiaPage, registerPageCanvas, registerExtensionConfigView } from '@tycms/api';
import LmsHome from './LmsHome.vue';
import CourseListCanvas from './CourseListCanvas.vue';
import LmsConfig from './LmsConfig.vue';

/** Le Core installe ses mixins, directives et stores lors du montage de cette page. */
export function registerFrontend() {
    registerRiaPage('lms/home', LmsHome);
    registerPageCanvas('lms/course-list', CourseListCanvas);
    // Vue de configuration résolue par l’écran Extensions du Core.
    registerExtensionConfigView('lms/config', LmsConfig);
}
