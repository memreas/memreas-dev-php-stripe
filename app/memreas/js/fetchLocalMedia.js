  function readFilesAndDisplayPreview(files) {
alert('readFilesAndDisplayPreview fired...');
    // Loop through the FileList and render image files as thumbnails.
    for (var i = 0, f; f = files[i]; i++) {
      // Only process image files.
      if (!f.type.match('image.*')) {
        continue;
      }
      var reader = new FileReader();
      // Closure to capture the file information.
      reader.onload = (function(theFile) {
        return function(e) {
          // Render thumbnail.
          var link = "<a href='#'><img src='" + e.target.result + "' alt='" + e.target.result + "' /></a>"; 
		  $("#content_1 .mCSB_container").append(link);
        };

      })(f);
      // Read in the image file as a data URL.
      reader.readAsDataURL(f);
    }
  }

  function handleFileSelect(evt) {
    var files = evt.target.files; // FileList object
    readFilesAndDisplayPreview(files);
  }

//  document.getElementById('dir').addEventListener('change', handleFileSelect, false);
