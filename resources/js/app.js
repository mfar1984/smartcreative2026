import './bootstrap';

// Basic rich-text editor for the Event description field only.
// Exposed on window so the inline init script in the event form can use it.
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

window.Quill = Quill;
