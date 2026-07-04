//Global Helper Functions

//Function getBaseURL
//returns baseURL
var getBaseURL = function () {
	var url = $('#project_url').val();
	url = url ? url : 'app.osgrandhorizon.io';
	if (url.substring(url.length - 1) !== "/") {
		url = url + '/';
	}
	return url;
}
