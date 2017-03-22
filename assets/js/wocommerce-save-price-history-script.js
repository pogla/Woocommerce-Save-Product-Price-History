jQuery(function( $ ) {
  $(".js-example-basic-multiple").select2({
  	placeholder: "Search product",
  	minimumInputLength: 3,
  	query: function (query) {

  	    var data = {results: []};

  	    $.ajax({
  	    	url : ajaxurl + "?term=" + query.term + "&action=woocommerce_json_search_products&security=" + securityObj.security,
  	    	success: function(responseData) {
  	    	    // console.log(responseData);
  	    	    for (var prop in responseData) {

    	            if(!responseData.hasOwnProperty(prop)) continue;

    	            data.results.push({id: prop, text: responseData[prop].replace('&ndash;','-')});

    	        }

    	        query.callback(data);

  	    	},
  	    });

  	}

  });

});
