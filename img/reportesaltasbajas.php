
<?php
include("seguridad.php");
require 'nuwconexion.php';

$select=$_POST['provee'];
$con=conectar();
$notie=$_SESSION["numsuc"];
$fech1=$_POST['fecha1'];
$fecha2=$_POST['fecha2'];
		       
//$sql2 = "SELECT o.cod_barra, p.Proveedor, o.costo_uni, o.cantidad, o.folio_factura, o.estatus, o.fecha_entrega FROM orden_compras_excedentes as o INNER JOIN proveedor as p ON o.clave_proveedor=p.clave_prov  where o.tienda='$notie' and clave_proveedor='$select'";

/*$sql2 = "SELECT o.fecha_entrega, o.nombre_proveedor, d.folio_fac, SUM(o.costo_uni) as total, p.dias_credito, (o.fecha_entrega + p.dias_credito) AS vencimiento, o.estatus_pago FROM orden_compras AS o INNER JOIN orden_compras_detalle AS d ON o.clave_proveedor = d.clave_proveedor and o.tienda=d.tienda INNER JOIN proveedor AS p ON o.clave_proveedor = p.clave_prov WHERE o.tienda = '2' AND O.fecha_entrega BETWEEN '$fecha1' AND '$fecha2' GROUP BY p.clave_prov";
$res=$con->query($sql2);*/

$sql2=$con->query("SELECT id_empleado, registro_patronal, no_afiliacion, apellido_paterno, apellido_materno, nombre, sueldo_quincenal, sdi, fecha_ingreso, curp, rfc, idcif, cp_emp, fecha_nacimiento, lugar_nacimiento, puesto, cp_tienda, salario_diario, factor, umf, fecha_alta, clave_tie, tienda FROM plantilla_personal WHERE estatus_trabajador='1' and fecha_alta BETWEEN '$fech1' AND '$fecha2' ORDER BY clave_tie ASC");
//$res=$con->query($sql2);


$sql3=$con->query("SELECT id_empleado, registro_patronal, no_afiliacion, apellido_paterno, apellido_materno, nombre, sueldo_quincenal, sdi, fecha_ingreso, curp, rfc, idcif, cp_emp, fecha_nacimiento, lugar_nacimiento, puesto, cp_tienda, salario_diario, factor, umf, clave_tie, tienda, fecha_baja, comentarios FROM plantilla_personal WHERE fecha_baja BETWEEN '$fech1' AND '$fecha2' ORDER BY clave_tie asc");
//$ress=$con->query($sql3);


//movimientos de puestos/salarios
$sql5=$con->query("SELECT apellido_paterno, apellido_materno, nombre, sueldo_quincenal, sueldo_quin_nuevo, salario_diario, salario_dia_nuevo, departamento, departamento_nuevo, puesto, puesto_nuevo, clave_tie, tienda,  zona, fecha_solicitud, id_emp, tipo_mov FROM plantilla_personal_movimientos WHERE tipo_mov='5' and fecha_solicitud BETWEEN '$fech1' AND '$fecha2' ORDER BY clave_tie asc");

//traspasos de tienda
$sql6=$con->query("SELECT apellido_paterno, apellido_materno, nombre, sueldo_quincenal, sueldo_quin_nuevo, salario_diario, salario_dia_nuevo, departamento, puesto,  clave_tie, tienda, tienda_nueva, clave_tie_nueva, zona, fecha_solicitud FROM plantilla_personal_movimientos WHERE tipo_mov='6' and fecha_solicitud BETWEEN '$fech1' AND '$fecha2' ORDER BY clave_tie asc");
//$resultadoS = $res->fetch_array(MYSQLI_ASSOC);
//$row=$resultadoS->fetch_array(MYSQLI_ASSOC);
//$resultadoS = $mysqli->query($sql2);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="description" content="">
<meta name="author" content="">
<title>Inputs dinamicos dinamicos usando jQuery y PHP - BaulPHP</title>

<!-- Bootstrap core CSS -->
<link href="dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" type="text/css" href="css/estilosubida.css" />
	<link rel="stylesheet" type="text/css" href="css/css8.css">
<!-- Custom styles for this template -->
<link href="assets/sticky-footer-navbar.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
	<link href="css/bootstrap.min.css" rel="stylesheet">
		<link href="css/bootstrap-theme.css" rel="stylesheet">
		<script src="js/jquery-3.1.1.min.js"></script>
		<script src="js/bootstrap.min.js"></script>
	 <script src="js/tableToExcel.js"></script>
	<link href="css/reporteprov.css" rel="stylesheet">
<script src="http://code.jquery.com/jquery-2.1.1.js"></SCRIPT>
<script>
function AgregarMas() {
	$("<div>").load("InputDpagos.php", function() {
			$("#productos").append($(this).html());
	});	
}
function BorrarRegistro() {
	$('div.lista-producto').each(function(index, item){
		jQuery(':checkbox', this).each(function () {
            if ($(this).is(':checked')) {
				$(item).remove();
            }
        });
	});
}
</script>
<script type="text/javascript">
        function toggle(elemento) {
          if(elemento.value=="a") {
              document.getElementById("externos").style.display = "";
              document.getElementById("directos").style.display = "none";
           }else{
               if(elemento.value=="b"){
                   document.getElementById("directos").style.display = "";
                   document.getElementById("externos").style.display = "none";
                               }
            }}
</script>
	<script>  
 $(document).ready(function(){  
      $('#tableToExcel').click(function(){  
           var excel_data = $('#myTable').html();  
           var page = "excel.php?data=" + excel_data;  
           window.location = page;  
      });  
 });
 
 $('btnDescargar').on('click',requestDescargar);
 
 
 </script>
	<?

function  requestDescargar(){
	 tableToExcel('myTable', 'Reporte de Pagos');
	 
	 }   

?>
	<script>  
 $(document).ready(function(){  
      $('#tableToExcel').click(function(){  
           var excel_data = $('#myTableL').html();  
           var page = "excel.php?data=" + excel_data;  
           window.location = page;  
      });  
 });
 
 $('btnDescargar').on('click',requestDescargarR);
 
 
 </script>
	
	<script type="text/javascript">
        function toggle(elemento) {
          if(elemento.value=="a") {
              document.getElementById("altas").style.display = "";
              document.getElementById("bajas").style.display = "none";
			  document.getElementById("movimientos").style.display = "none";
              document.getElementById("traspasos").style.display = "none";
			  
           }else{
               if(elemento.value=="b"){
                   document.getElementById("altas").style.display = "none";
                   document.getElementById("bajas").style.display = "";
				   document.getElementById("movimientos").style.display = "none";
              		document.getElementById("traspasos").style.display = "none";
                               }
			   else{
               if(elemento.value=="c"){
				    document.getElementById("altas").style.display = "none";
				   document.getElementById("bajas").style.display = "none";
                   document.getElementById("movimientos").style.display = "";
                   document.getElementById("traspasos").style.display = "none";
				   
                               }
				   else{
               if(elemento.value=="d"){
				    document.getElementById("altas").style.display = "none";
              		document.getElementById("bajas").style.display = "none";
                   document.getElementById("movimientos").style.display = "none";
                   document.getElementById("traspasos").style.display = "";
				   
                               }
            }}}}
</script>
	
</head>

<?	
	function  requestDescargarR(){
	 tableToExcel('myTableL', 'Reportes Pagos');
	 
	 } 
	
	?>
	
<body>
<header> 
  <!-- Fixed navbar -->
  
    

</header>

<!-- Begin page content -->

	<div class="selectorr">				
<form  method="post" action="" enctype="multipart/form-data" class="contenedorf">  
	
	<table><tr><th><strong>ALTAS</strong></th><th><input type="radio" name="tipo_attach" onClick="toggle(this)" value="a" class="radiob"></th><th></th><th></th><th><strong>BAJAS</strong></th><th><input type="radio" name="tipo_attach" onClick="toggle(this)" value="b"  class="radiob"></th><th></th><th></th><th><strong>MOVIMIENTOS</strong></th><th><input type="radio" name="tipo_attach" onClick="toggle(this)" value="c"  class="radiob"></th><th></th><th></th><th><strong>TRASPASOS</strong></th><th><input type="radio" name="tipo_attach" onClick="toggle(this)" value="d"  class="radiob"></th><th><a href="menuopee.php"><input type="button" name="salir" value="salir"  class="botonmenu" /></a></th></tr></table>

</form></div>
	
	<div class="contenedorreportest">
 <center>
   <h3 class="mt-5"><strong>REPORTES DE EMPLEADOS</strong></h3></center>
	                
          <div  class="userexede"><strong><strong>Usuario: <? echo $_SESSION["usuarioactual"];?></strong></div>
  
		</div>	 
      <div id="marco1"> </div>
				  
      <div class="contenidoreportes" id="altas" style="display:none;">
		  <CENTER><h3 class="mt-5"><strong>DESCARGAR REPORTES DE ALTAS</strong></h3></center></CENTER><BR>
	 <form name="frmProduct" method="post" action="repaltas.php" target="contenido">
			 
			  <table><tr><th width="100px" height="auto" style=" margin-right:40px; align:right"></th><th style="margin-left:50px;  align=left"></th><th></th><th><label>Fecha Inicio:</label></th><th align="left"><input type="date" value="Y-m-d"  name="fecha1" ></th><th></th><th style="margin-right:50px; text-align: right"><label>Fecha Fin:</label></th><th align="left"><input type="date" value="Y-m-d" name="fecha2" ></th><th></th><th></th><th style="margin-right: 40px; text-align: right"><input  type="submit" id="enviar" name="enviar" value="DESCARGAR" class="btn btn-success"/> </th><th></th><th></th></tr></table>

	
	    </form></div>
  

			 
<!-- Fin container --><!-- Bootstrap core JavaScript
    ================================================== --> 
<!-- Placed at the end of the document so the pages load faster --> 
	 
	  
 <div class="contenidoreportes" id="bajas" style="display:none;">
		  <CENTER><h3 class="mt-5"><strong>DESCARGAR REPORTES DE BAJAS</strong></h3></center></CENTER><BR>
	 <form name="frmProduct" method="post" action="reporbaja.php" target="contenido">
			 
			  <table><tr><th width="100px" height="auto" style=" margin-right:40px; align:right"></th><th style="margin-left:50px;  align=left"></th><th></th><th><label>Fecha Inicio:</label></th><th align="left"><input type="date" name="fecha1" ></th><th></th><th style="margin-right:50px; text-align: right"><label>Fecha Fin:</label></th><th align="left"><input type="date" name="fecha2" ></th><th></th><th></th><th style="margin-right: 40px; text-align: right"><input  type="submit" id="enviar" name="enviar" value="DESCARGAR" class="btn btn-success"/> </th><th></th><th></th></tr></table>

	
	    </form></div>	  
			  
			 <!-- Fin row -->  
	
		<div class="contenidoreportes" id="movimientos" style="display:none;">
	<CENTER><h3 class="mt-5"><strong>DESCARGAR REPORTES DE MOVIMIENTOS</strong></h3></center></CENTER><BR>
	<FORM name="frmProduct" method="post" action="repmovimientosp.php" target="contenido">
		
		<table><tr><th width="100px" height="auto" style=" margin-right:40px; align:right"></th><th style="margin-left:50px;  align=left"></th><th></th><th><label>Fecha Inicio:</label></th><th align="left"><input type="date" name="fecha1"></th><th></th><th style="margin-right:50px; text-align: right"  ><label>Fecha Fin:</label></th><th align="left"><input type="date" name="fecha2"></th><th></th><th></th><th style="margin-right: 40px; text-align: right"><input  type="submit" id="enviar" name="enviar" value="DESCARGAR" class="btn btn-success"/> </th><th></th></tr></table>
		
		</form>

			  </div>
  <!-- Fin row --> 

  <div class="contenidoreportes" id="traspasos" style="display:none;">
	<CENTER><h3 class="mt-5"><strong>DESCARGAR REPORTES DE TRASPASOS</strong></h3></center></CENTER><BR>
	<FORM name="frmProduct" method="post" action="repmovimientostras.php" target="contenido">
		
		<table><tr><th width="100px" height="auto" style=" margin-right:40px; align:right"></th><th style="margin-left:50px;  align=left"></th><th></th><th><label>Fecha Inicio:</label></th><th align="left"><input type="date" name="fecha1"></th><th></th><th style="margin-right:50px; text-align: right"  ><label>Fecha Fin:</label></th><th align="left"><input type="date" name="fecha2"></th><th></th><th></th><th style="margin-right: 40px; text-align: right"><input  type="submit" id="enviar" name="enviar" value="DESCARGAR" class="btn btn-success"/> </th><th></th></tr></table>
		
		</form>

			  </div>

			 
<!-- Fin container --><!-- Bootstrap core JavaScript
    ================================================== --> 
<!-- Placed at the end of the document so the pages load faster --> 

<script src="dist/js/bootstrap.min.js"></script>	  
	  
	
</body>
</html>