/* Version 6.3.1 - TinyMCE plugin to insert [nb_camera popup="1"] */
(function() {
  // TinyMCE 4-compatible registration (Classic Editor)
  if (typeof tinymce === 'undefined' || !tinymce.PluginManager) { return; }
  tinymce.PluginManager.add('nb_camera', function(editor) {
    editor.addButton('nb_camera_button', {
      text: 'Camera',
      tooltip: 'Insert NB Camera shortcode (popup)',
      onclick: function() {
        editor.insertContent('[nb_camera popup="1"]');
      }
    });
    // Also add to Insert menu if present
    if (editor && editor.addMenuItem) {
      editor.addMenuItem('nb_camera_button', {
        text: 'NB Camera',
        context: 'insert',
        onclick: function() {
          editor.insertContent('[nb_camera popup="1"]');
        }
      });
    }
    return {
      getMetadata: function() {
        return { name: 'NB Camera', version: '6.3.1' };
      }
    };
  });
})();
