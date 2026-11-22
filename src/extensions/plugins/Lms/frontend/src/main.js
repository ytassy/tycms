import { registerRiaPage, registerPageCanvas } from '@tycms/api';
import LmsHome from './LmsHome.vue';
import CourseListCanvas from './CourseListCanvas.vue';

/** Le Core installe ses mixins, directives et stores lors du montage de cette page. */
export function registerFrontend() {
    registerRiaPage('lms/home', LmsHome);
    registerPageCanvas('lms/course-list', CourseListCanvas);
}
