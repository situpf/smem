#!/usr/bin/php
<?php
/*
 
****************************************************************************
*                                                                          *
*                                SMEM                                      *
*                                                                          *
*                                                                          *
*                                  														*
*                            Author: miguelangel.sanchez@upf.edu           *                                                  
*                                    (Scientific IT Core Facility (UPF))   *
*                            Originally based in the tool QMEM             *
*																									*
****************************************************************************

SMEM is a tool originally based in tool QMEM that was writed to work with 
SunGrid Engine. 

SMEM show us the current state of the resources available in a Slurm cluster 
with the possibility to choose if we want to view the jobs that are allocating
the resources.

HOW TO USE IT:

Description:

  Shows the resource usage of all the cluster nodes.

Usage:

  smem	[[-u] [<user-list>]] [-p <partition-name>] [-w <host-list>] [[-g] [<resources-list>]]

  -u [<user-list>]     Shows the resources in use by all the jobs currently
                         running.  If the user list is present, shows only the
                         running jobs that belong to the users list. The resource
                         list has to be comma separated list.
  -p <partition-list>      Shows information only for the given partitions The partitions has to be.
									a comma separated list.
  -g [<resource-list>] Show the usage of the general resources. If the resource
                         list is  present, shows only the usage of the resources
                         that are in the list. The resource list has to be comma
                         separated list.
  -w <host-list>       Show information only for the given host list. The host list
                         has to be a comma separated list.
  -h                   Shows this help.

*/

// Definició de colors 

define('DARKBLUE',"\x1b\x5b1;34;49m");
define('DARKRED',"\x1b\x5b0;31;49m");
define('LIGHTRED',"\x1b\x5b1;31;49m");
define('DARKGREEN',"\x1b\x5b0;32;49m");
define('LIGHTGREEN',"\x1b\x5b1;32;49m");
define('DARKYELLOW',"\x1b\x5b0;33;49m");
define('LIGHTYELLOW',"\x1b\x5b1;33;49m");
define('PURPLE',"\x1b\x5b1;35;49m");
define('DEFAULTC',"\x1b\x5b0;39;49m");

// Arguments 

$param_jobs=false;	// -u [<user-list>]
$param_gres=false;	// -g [<gres-list>]
$param_partition=false;	// -p <partitions_name>
$param_host=false;	// -w <host-list>
$partition_list="null";			// per defecte, all partitions. Change if -p is selected
$users_array=array();  // Va relacionada amb $param_jobs. Es la llista d'usuaris de la que volem veure els jobs (opcional)
$host_list="null";

// Si volem indicar els recursos gres a visualitzar ho hem d'afegir amb aquesta variable (llista separada per comes)
// Si es deixa aquesta variable a null, es mostraran tots els recursos gres definits en el cluster
// Atenció: sempre tindrà preferencia els recursos que l'usuari indiqui amb el flag '-g'
$custom_gres=null;
//$custom_gres="gpu";

// Comprovem que totes les eines de Slurm que necesita aquest script estan disponibles en el sistema.
// Aquesta comprovació també existeix per quan l'ordinador on s'executa aquesta eina no esta veïent cap cluster amb Slurm.
exec("which scontrol sstat sinfo sacctmgr sacct | wc -l", $exec_tools_av);
if ($exec_tools_av[0] < 5) {
	echo "ERROR: Neither some Slurm tools needed to run this script nor any Slurm cluster are not available.\n\n";
	exit (1);
}

// Fem una petita comprovació per assegurar-nos de que el cluster està funcionant correctament.
exec("sinfo | wc -l", $exec_check_if_ok);
if ($exec_check_if_ok[0] == 0) {
	echo "\nERROR: Is the Slurm cluster working properly?\n\n";
	exit (1);
}

// Aquest sript requereix una versió de Slurm superior o igual a la 16.
exec("scontrol -V | awk '{ print $2 }' | cut -d \".\" -f 1 ", $exec_vers);
if ($exec_vers[0] < 16) {
	echo "ERROR: This script works with Slurm version >= 16.\n\n";
	exit (1);
}

// Abans de res comprobem que la memória sigui un recurs consumible. Si no ho és ja podem parar l'execució.
// Nota: Tots els possibles valors de la directiva SelectTypeParameters que tenen el recurs de memória com a 
// consumible tenen com a substring la paraula 'MEMORY'.
exec("scontrol show config | grep -e \"SelectTypeParameters\"| awk '{ print $3 }'", $exec_cons);

if (strpos($exec_cons[0], 'MEMORY') == false) {
	echo "ERROR: Memory is not configured in the Slurm configuration files as a consumible atribute.\n\n";
   exit(1); 
}

// Obtenim la hora actual per calcular el runtime dels jobs 
$now=time();

// Obtenim la llista d'usuaris defints en el cluster
exec("sacctmgr list users --noheader format=User%-20", $exec_full_users_list);

// Comencem amb el tractament dels arguments 
check_params ($argv, $exec_full_users_list);

// Obtenim la llista dels GRES
$gres = get_gres_list ($custom_gres);

// Obtenim la llista de les particions
$partition_list = partition_list ($param_partition, $partition_list);

// Obtenim la llista dels usuaris si no ens han dit de quins usuaris volen 
// veure els jobs.
if (empty($users_array))
	$users_array = $exec_full_users_list;

// Obtenim la llista de nodes que hem de tractar, es a dir, si ens han demanat només veure la
// info de uns nodes en concret o els nodes de una partició tindrem en aquesta variable aquests nodes.
$nodes_to_list = node_list ($param_host, $host_list, $partition_list); 

// Obtenim la llista de nodes que no estan operatius
exec ("sinfo -N --states=DOWN,DRAIN,DRAINED,DRAINING -o \"%N\" --noheader", $unav_nodes);

// Obtenim la info de com estàn els nodes de càlcul
$computer_resources = extract_computers_info ();

// Si ho han demanat, preparo també les dades dels jobs en execució.
if ($param_jobs) 
    $job_resources=extract_jobs_info();

$total_running=0;
$total_cpus=0;
$max_cpus_node=0;	 // Aqui guardarem el valor del numero de cores del node que mes té. Es farà servir per poder fer una correcta alineació de la info en el printing.
$total_users_cpus=0; // Aqui simem les cpu qu estan fent servir els usuaris, ho mostrem si amb el -u ens han possat una llista d'usuaris. 
$computes=null;		 // Variable principal on guardarem la informaicó de l'estat dels nodes i dels jobs 

// En aquest punt ja tenim la foto del estat del cluster a les variables $computer_resources i $job_resources (si l'usuari
// ha demanat veure també la info dels jobs). Ara hem de ficar aquesta info a la variable $computer per que sigui després mostrada
// per pantalla.

foreach ($nodes_to_list as $cmp) {

	// Si el node pertany a una de les ques de la qual hen de mostrar la info
//	if (belong_to_partition($computer_resources[$cmp]['partitions'], $queue) ) {

    	$computes[$cmp] = fill_computer_info ($cmp, $computer_resources[$cmp]);
    
    	if ($param_jobs) 
      	  $computes[$cmp]['job_array'] = fill_jobs_info ($cmp, $job_resources,$partition_list);

		$total_cpus+=$computes[$cmp]['maxjobs'];
		$total_running+=$computes[$cmp]['jobs'];
//	}
} 

// Ara que ja tenim l'estructura $computes feta, ja podem sumar la quantitat en us per els jobs en cada compute, sempre i quan
// l'usuari hagi fet servir la opció -u (si no es així l'estructura $computes no te la info necesaria per fer aquest càlcul)
if ($param_jobs)  
	sum_mem_used($computes);

// Part final. Ja tenim tota la info que ens han demanat a la variable $computes. Ja ho podem mostrar per pantalla.
// Nota: hem de tindre en compte que aquesta funcio printa el que hi ha a l'estructura de dades $compute que només conté
// la informació que volem veure (ex. els nodes que estan a la qua de la qual volem veure la info)
printing ();

// ***** DEFINICIÓ DE FUNCIONS ***** \\

function check_params ($argv, $full_users_list) {

	global $param_jobs, $param_gres, $custom_gres, $param_partition, $param_host, $host_list, $partition_list, $users_array;

	$param_ok=true;
	foreach ($argv as $key => $param) {
	
		if ($param == $argv[0]) {
			// No fem res, es el nom del propi script
		} else if ($param == "-u") {
			$param_jobs=true;
			// Miro si junt amb el -u m'han passar una llista d'usuaris
			if (isset($argv[$key+1])) {
				// Hi ha alguna cosa més després del '-u'. Comprovem que sigui una llista de usuaris separats per coma
		    	// i que no sigui un altre flag (que no comenci per guió). 
		    	if (preg_match("/.+(,.+)*/",$argv[$key+1]) && ! preg_match("/^-.+/",$argv[$key+1])) {
		    		$users_list=$argv[$key+1];
		    	}			
			}		
			
		} else if ($param == "-g") {
			$param_gres=true;
			// Miro si junt amb el -g m'han passat una llista de gres a visualitzar
			if (isset($argv[$key+1]))
		   	// Hi ha alguna cosa més després del '-g'. Comprovem que sigui una llista de un element o més separats per coma
		    	// i que no sigui un altre flag (que no comenci per guió). Possible problema si hi ha un gres definit al Slurm
		    	// que comenci per un guió, pero tinc dubtes de que aixó es pugui fer.
		    	if (preg_match("/.+(,.+)*/",$argv[$key+1]) && ! preg_match("/^-.+/",$argv[$key+1]) ) 
		      	$custom_gres=$argv[$key+1];
		} else if ($param == "-p" && isset($argv[$key+1])) {
			if (preg_match("/.+(,.+)*/",$argv[$key+1]) && ! preg_match("/^-.+/",$argv[$key+1])) {
				$param_partition=true;
				$partition_list=$argv[$key+1];
			} else
				// El que hi havia a continuaicó del '-w' no era una llista d'elemts separada per comes, poder era un altre flag, so, falta la llista de nodes.
				$param_ok=false;	
		} else if ($param == "-w" && isset($argv[$key+1]))  {
			if (preg_match("/.+(,.+)*/",$argv[$key+1]) && ! preg_match("/^-.+/",$argv[$key+1])) {
				$param_host=true;
				$host_list=$argv[$key+1];
			} else 	
				// El que hi havia a continuaicó del '-w' no era una llista d'elemts separada per comes, poder era un altre flag, so, falta la llista de nodes.
				$param_ok=false;	
		} else {
	   	if ($argv[$key-1] != "-p" && $argv[$key-1] != "-g" && $argv[$key-1] != "-u" && $argv[$key-1] != "-w") {
				$param_ok=false;
			}
		}
	}

	// Si ens han passat una llista d'usuaris hem de comprovar que existeixin almenys en el sistema
	if (isset($users_list) && $param_ok) {
		$users_array=explode(",", $users_list); 
		if ($user_not_exist=no_exist_users ($users_array, $full_users_list)) {
			echo "ERROR: The user/s $user_not_exist are not defined in the system.\n\n";
   		exit(1); 
		}	else 
			echo "USERS:\t$users_list \n";
	}

	if ( !$param_ok ) 
		show_help ();

}

function show_help () {

	echo ("\nDescription:\n\n");
	echo ("  Shows the resource usage of all the cluster nodes.\n\n");
	echo ("Usage:\n\n");
	echo ("  smem\t[[-u] [<user-list>]] [-p <partitions-name>] [-w <host-list>] [[-g] [<resources-list>]]\n\n");
	echo ("  -u [<user-list>]       Shows the resources in use by all the jobs currently\n");
	echo ("                           running.  If the user list is present, shows only the\n"); 
	echo ("                           running jobs that belong to the users list. The resource\n"); 
	echo ("                           list has to be comma separated list.\n");
	echo ("  -p [<partitions-list>] Shows information only for the given partitions The partitions has to be.\n");
	echo ("								    a comma separated list.\n");
	echo ("  -g [<resource-list>]   Show the usage of the general resources. If the resource\n"); 
	echo ("                           list is  present, shows only the usage of the resources\n"); 
	echo ("                           that are in the list. The resource list has to be comma\n"); 
	echo ("                           separated list.\n");
	echo ("  -w <host-list>         Show information only for the given host list. The host list\n"); 
	echo ("                           has to be a comma separated list.\n");
	echo ("  -h                     Shows this help.\n\n");
	
	exit(1);

}

function no_exist_users ($users_array, $full_users_list) {

	$out=null;
	
	$comma = "";
	foreach ($users_array as $user_name) {

		if (! in_array ($user_name, $full_users_list)) {
			$out .=$comma.$user_name;
			$comma=",";
		}
	
	}

	return $out;

}

function get_gres_list ($custom_gres) {

	// Preparació la variable que contindrà els GRES definits al cluster.
	exec("scontrol show config | grep -e \"GresTypes\" | awk '{ print $3 }'", $exec_gres);

	if ($exec_gres[0] != "(null)") {
		
    	if (is_null($custom_gres)) {
        // No hi ha una llista de gres definida per l'usuari ni per linia de comandes ni en hardcode.
        $values = explode(",", $exec_gres[0]);
        foreach ($values as $i) 
            $gres[$i]=null;
            
    	} else {
    		
        $values = explode(",", $exec_gres[0]);
        // Hi ha una llista de gres definida per l'usuari
        $custom_gres_array=explode(",",$custom_gres);
        foreach($custom_gres_array as $i) {
            // Comprobo que existeixi en el cluster el gres definit.
            if (in_array($i,$values))
                $gres[$i]=null;
            else {
                // No existeix aquest recurs definit com a gres
                echo "ERROR: The GRES $i is not defined in the Slurm configuration files.\n\n";
                exit(1);
            }
        }
    	}
	} else if (! is_null($custom_gres)) {
   	// L'usuari per hardcode o per línies de comandes ha definit que vol veure un GRES pero
    	// en el cluster no hi ha cap GRES definit.
    	echo "ERROR: Not exist any GRES resource defined in the Slurm configuration files.\n\n";
    	exit(1);
	} else
    	// No hi ha cap gres definit al cluster
		$gres = array();;

	return $gres;

}

function node_list ($param_host, $host_list, $partition_list) {
	
	// Llista de tots els nodes presents a les particions que visualitzarem (si l'usuari no ha especificat cap serán totes les particions)	
	exec("sinfo -h -p $partition_list -o %n", $all_nodes);
	
	if ($param_host) {
		
		// Miro si la llista de nodes que ens han passar existeix en el cluster
		$nodes_list = explode(",", $host_list);
		foreach ($nodes_list as $node ) {
			if (! in_array ($node, $all_nodes)) {
				// L'usuari ha especificat una llista de nodes i algun d'ells no existeix en el cluster. 
    			echo "ERROR: The compute node $node doesn't exist in any of the selected partitions ($partition_list).\n\n";
    			exit(1);
			}
		}
		
		$out = $nodes_list;
		// Mostren el nom de la particio
		echo "NODES:\t$host_list\n";
	
	} else
		// L'usuari no demna veure només una llista de nodes en concret. 
		$out = $all_nodes;

	// Per alguna raó el exec hem retorna la llista desendreçada quan el sinfo la retorna endreçada, no ho entenc. Per
	// aixó la tinc que endreçar.
	sort ($out);
	
	return $out;

}

function partition_list ($param_partition, $partition_list) {

	// Llista de particions en funció de si s'ha especificar el flag "-p"
	exec("scontrol show partitions | grep PartitionName | cut -d \"=\" -f 2", $valid_partitions);
	
	if ($param_partition) {
	
		// Nomes volen veure la info d'una partició
		$q_ok=true;
		$partition_list_array = explode(",",$partition_list);
		foreach ($partition_list_array as $qname) { 
			if (! in_array($qname, $valid_partitions)) {
				echo "ERROR: The partition $qname doesn't exist in the cluster.\n";
				exit(1);				
			
			}
		}

		// Mostren el nom de la particio
		echo "PARTITION: $partition_list\n";
	
	} else {
		
		$partition_list = $comma = "";
		
		// Mostrem la info de totes les particions
		foreach ($valid_partitions as $qname) { 
			$partition_list .= $comma.$qname;
			$comma=",";
		}
	}

	return $partition_list;

}

function printing () {
    
    // Aquesta funció la tenim dividida en varies subfuncions, d'aquesta manera separem la part de printing en varies:
    //    1.- Mostrar la capcelera
    //    2.- Mostrar la info dels nodes i per cada node els seus jobs (dos funcions)
    //    3.- Mostrar la info final
    
    print_header ();
    
    print_compute_info();
    
    print_tail();
        
    
}

function print_header () {
    
   // Mostrem la capçalera 
   global $param_jobs, $max_cpus_node, $param_gres, $gres;
         
	// Si si ens han passat el flat -u per qe si no es així, no mostrem la columna de 'Used' en Memory.
	if ($param_jobs){ 
   	$header1=DEFAULTC."\t\t\t\t\t\t\t\t\t Memory\t\t      CPU    ";
   	$header2=DEFAULTC."Compute\t\t\t    Usage\t       Percentage\t   Used Reserved  Total\t   Used Total";
   } else {
		$header1=DEFAULTC."\t\t\t\t\t\t\t\t     Memory\t      CPU    ";
   	$header2=DEFAULTC."Compute\t\t\t    Usage\t       Percentage\tReserved  Total\t   Used Total";   
   }
        
   // ALIGN CPUs #'s TO THE RIGHT
   $str_spaces_cpus_disk=" ";
   if ($max_cpus_node > 2) {
   	$str_spaces_cpus_disk=str_repeat(" ",$max_cpus_node-2);
   }
        
   if ($param_gres) {
      $header2_gres="";
      foreach ($gres as $elem => $value) {
      		$header2_gres.="\t".$elem;
      }
      $header1_gres = align("GRES", strlen($header2_gres));
      $header1 .= $header1_gres.DEFAULTC;
   	$header2 .= $header2_gres.DEFAULTC;
   }
   echo shell_exec("printf '".$header1."\n'");
	echo shell_exec("printf '".$header2."\n'");

}

function print_compute_info () {
    
    // NOTA: S'ha de tindre en compte que quasi el 100% d'aquest codi es va heredar del qmem.
    
    global $computes, $param_jobs, $param_gres, $unav_nodes, $partition_list; 
    
    // Obtinc la memòria del node que te mes memòria.
    $max_mem_len=0;
    foreach ($computes as $cmp) {
        if (strlen($cmp['mem max']) > $max_mem_len) {
            $max_mem_len=strlen($cmp['mem max']);
        }
    }
    
    // Obtinc la quantitat de cpu del node que te més cpu's.
    $max_len_cpus=0;
    foreach ($computes as $c => $cmp ) {
        if ( $cmp['maxjobs'] > $max_len_cpus ) {
            $max_len_cpus=$cmp['maxjobs'];
        }
    }
    
    foreach ($computes as $compute => $comp) {
    
    	  // Primer: preparació de les dades que hem de mostrar:
        
        $ratio_reserved = 0;
        // Abans de fer operacions matemàtiques amb els valors, ens assegurem de que estan en KB.
        $mem_reserved_KB = convert_to_KB ($computes[$compute]['mem reserved']);
        $mem_max_KB = convert_to_KB ($computes[$compute]['mem max']);
        $ratio_reserved = $mem_reserved_KB/$mem_max_KB;
        
        $ratio_maxvmem = 0;
        // Abans de fer operacions matemàtiques amb els valors, ens assegurem de que estan en KB.
        $mem_used_KB = convert_to_KB ($computes[$compute]['mem used']);
        $mem_max_KB = convert_to_KB ($computes[$compute]['mem max']);
        $ratio_maxvmem = $mem_used_KB/$mem_max_KB;
        
        $spent = 0;
        $occupied = 0;
        $free = 1;
        
        // Quants # mostrem per visuatlitzar l'ús de la memoria de cada node (originalment eren 50)
        $long=30;
        
        $spent = ceil($ratio_maxvmem*$long);
        $occupied = ceil($ratio_reserved*$long)-$spent;
        
        $free = $long-$occupied-$spent;

        // Aquí ja preparen les barres amb els # amb la quantitat de caràcters segons l'ocpació de la memòria.
        $spent_str=str_repeat("#",$spent);
        $occupied_str=str_repeat("#",$occupied);
        $free_str=str_repeat("#",$free);
        
        $percent=0;
        $percent=round($ratio_reserved*100,1);
        $color_percent=paint($percent,100); 
        
        $percent=align($percent,5);
        
        $reserved=align($computes[$compute]['mem reserved'],6);
        $used=align($computes[$compute]['mem used'],6);

        $total=align($computes[$compute]['mem max'], $max_mem_len);
        
        $max_jobs=$computes[$compute]['maxjobs'];
        $color_jobs=paint($computes[$compute]['jobs'], $max_jobs);
        
        $cpu_occupied_str = str_repeat("#", $computes[$compute]['jobs']);
        
        $cpu_free_str=str_repeat("#",$max_jobs-$computes[$compute]['jobs']);

        $extra_spaces_cpus=str_repeat(" ",$max_len_cpus-$computes[$compute]['maxjobs']);
        
        // Tractem els gres del node: disponibles i ocupats.
        $gres_info="";
        if ($param_gres)
        		foreach ($computes[$compute]['gres_av'] as $resource => $value) 
           		$gres_info .= $computes[$compute]['gres_used'][$resource]." / ".$value."\t";
        
        // Segon: impimim per pantall la info del node fent servir les dades preparades.
        
        // PRINT GLOBAL NODE INFO
        if ( in_array($compute,$unav_nodes)) {
            // El node està down
            $color_jobs=DARKBLUE;
            
            // Si no ens demanen veure els jobs no cal treure el valor de Used Memory per que no està calculat.
            if ($param_jobs)
            	$compute_header_str=DARKBLUE.$compute."\t".$spent_str.$occupied_str.$free_str."  ".$percent." %% \t".$used." / ".$reserved." / ".$total."    ".$color_jobs.align($computes[$compute]['jobs'],2)." / ".$color_jobs.align($max_jobs,2)."\t".$gres_info;
            else
            	$compute_header_str=DARKBLUE.$compute."\t".$spent_str.$occupied_str.$free_str."  ".$percent." %% \t".$reserved." / ".$total."    ".$color_jobs.align($computes[$compute]['jobs'],2)." / ".$color_jobs.align($max_jobs,2)."\t".$gres_info;
        } else {
         	// El node està up
         	//  Aquesta es la linea original per veure la info de un node, li trec els # que representen les CPU en us.
         	//	$compute_header_str=DEFAULTC.$compute.":\t".DARKRED.$spent_str.LIGHTRED.$occupied_str.LIGHTGREEN.$free_str.$color_percent."  ".$percent." %% \t".DARKRED.$used.DEFAULTC." / ".LIGHTRED.$reserved.DEFAULTC." / ".LIGHTGREEN.$total."    ".$color_jobs.align($computes[$compute]['jobs'],2).DEFAULTC." / ".$color_jobs.align($max_jobs,2).DEFAULTC."$extra_spaces_cpus (".DARKBLUE.$cpu_suspended_str.DARKRED.$cpu_occupied_str.LIGHTGREEN.$cpu_free_str.DEFAULTC.")"."\t".$gres_av;
				if ($param_jobs)
         		$compute_header_str=DEFAULTC.$compute."\t".DARKRED.$spent_str.LIGHTRED.$occupied_str.LIGHTGREEN.$free_str.$color_percent."  ".$percent." %% \t".DARKRED.$used.DEFAULTC." / ".LIGHTRED.$reserved.DEFAULTC." / ".LIGHTGREEN.$total."    ".$color_jobs.align($computes[$compute]['jobs'],2).DEFAULTC." / ".$color_jobs.align($max_jobs,2).DEFAULTC."\t".$gres_info;
         	else 
         		$compute_header_str=DEFAULTC.$compute."\t".DARKRED.$spent_str.LIGHTRED.$occupied_str.LIGHTGREEN.$free_str.$color_percent."  ".$percent." %% \t".LIGHTRED.$reserved.DEFAULTC." / ".LIGHTGREEN.$total."    ".$color_jobs.align($computes[$compute]['jobs'],2).DEFAULTC." / ".$color_jobs.align($max_jobs,2).DEFAULTC."\t".$gres_info;
         }	

        	echo shell_exec("printf '".$compute_header_str."\n'");

        	// Tercer: preparem e imprimim les dades dels jobs.

        	// Si l'usuari ha fer indicar el paràmetre -u, hem de treure la info dels jobs
        	if ($param_jobs && isset($computes[$compute]['job_array']) && sizeof($computes[$compute]['job_array']) > 0)
           		print_jobs_info ($computes[$compute]['job_array']);
	 }
    
}

function belong_to_partition($compute_partitions, $partition_list) {
	
	// Convertim les llistes separades per comas a un array
	$compute_partitions_array = explode(",", $compute_partitions);
	$partitions_array = explode(",",$partition_list);
	
	$out=false;
	foreach ($compute_partitions_array as $part_name) {
		if (in_array ($part_name,$partitions_array))
			$out = true;	
	}

	return $out;

}

function print_jobs_info ($job_array) {
    
    global $param_gres, $now, $users_array, $total_users_cpus;
    
    // A $job_array tenim els jobs en run del computer que estem printant.
        
    // Definim unes constant que ens serveixen per definir els tamany d'alguns strings que hem de printar com el nom del usuari.   
    $max_len_user=10;
    $max_len_name=40;

    foreach ($job_array as $jobid => $job) {
        
        if ( strlen($job['user']) > $max_len_user && in_array($job['user'], $users_array)) 
            $max_len_user=strlen($job['user']);
            
    }
    
    foreach ($job_array as $jobid => $job) {
        
		  
        if (in_array($job['user'], $users_array)) {
        	
        		// Aixo de align no se que fa, crec que es alguna cosa que el printf si que entén i li
        		// diu com ha de mostrar les dades per pantalla (el estil).
        		$user = align($job['user'],$max_len_user+3);
        		$id = align($jobid,12);
            
        		$jobname=$job['jobname'];
            
        		if (strlen($jobname) > $max_len_name) 
            		$jobname=substr($jobname,0,$max_len_name);
            
        		$jobname.=str_repeat(" ",$max_len_name-strlen($jobname));

        		$maxvmem = align($job['maxvmem node'],9);
        		$reserved = align($job['mem reserved node'],5);
            
        		// Com que hem d'operar per treure percentatges ho fem amb tot a la mateixa unitat
        		$max_vmem_node_KB=convert_to_KB($job['maxvmem node']);
        		$mem_reserved_node_KB=convert_to_KB($job['mem reserved node']);
        
        		$percent=round(($max_vmem_node_KB/$mem_reserved_node_KB)*100,1);
        		$color_percent=paint_reverse($percent,100);
        		$percent=align($percent ,4);
            
        		# echo "PERCENT: $percent "; print_r ($job['maxvmem node']); echo " "; print_r ($job['mem reserved node']); echo " \n";
            
        		$cpu="";
        		if ($job['maxcpus'] > 1) 
           		$cpu=$job['cpus']."/".$job['maxcpus']." cpus  ";
            
        		$cpu=align($cpu,11);
            
        		// CALCULATE WALLCLOCK TIME
        		$temps = $job['start time'];
        		$epoch = $now-date('U',strtotime($temps));
            
        		$temps=date('d/m/Y H:i:s',strtotime($temps));  // ¿?
            
        		$day=$hour=$min=$sec="00";
        
        		if ($temps > 0) {
            
            	$min = floor($epoch/60);
            	$sec = $epoch-($min*60);
            	$hour=floor($min/60);
            	$min=$min-($hour*60);    
            	$day=floor($hour/24);
            	$hour=$hour-($day*24);
                
            	if ($sec < 10)  
               	$sec="0$sec";
            
            	if ($min < 10)
                	$min="0$min";
            
            	if ($hour < 10)  
               	$hour="0$hour";
            
        		}
        
        		if ($day <= 0) 
            	$cpu_time="$hour:$min:$sec";
        		else 
            	$cpu_time="$day:$hour:$min:$sec";
            
        		$job_array[$id]['cpu']=$cpu_time; // ¿?
        		$cpu_time=align($cpu_time,12);
            
        		if ($job['status'] == "running")    // Es una mica absurd aquest if per que només mostrem job en running, ho deixo per si en algun moment mostrem altres tipus de jobs 
            	$job_str=" ".$color_percent.$user.DEFAULTC."  ".$id."  ".$color_percent.$percent." %% ".$maxvmem.DEFAULTC." / ".$color_percent.$reserved.DEFAULTC."  ".$jobname." ".$cpu_time."  ".$cpu;

        		// Preparem la info sobre els GRES si ho ha demanat el usuari.
        		$gres_use="";
        		if ($param_gres) {
            	foreach ($job['gres'] as $resource => $value) {
                	if (is_null($value))
                    $gres_use.=DEFAULTC."0\t";
                	else
                    $gres_use.=DEFAULTC.$value."\t";
                            
            	}	
        		}
            
        		// PRINT TEXT

        		// Aquesta xorrada la faig per que en una ocasió hem vaig trobat amb un % en el nom del job ¿?
//        		$job_str = str_replace ("%","%%",$job_str);
//        		$job_str = str_replace ("\\","\\\\",$job_str);

        		echo shell_exec("printf '".$job_str.$gres_use.DEFAULTC."\n'");
        		
        		// Vaig sumant el total cpu que tenen en us els usuaris que estem mostrant. Ho printatem si amb el -u han possat
        		// noms de usuaris.
        		$total_users_cpus+=$job['cpus'];
        		
        		
        }
    }
    
}

function print_tail () {
    
    global $param_html, $total_suspended, $total_running, $total_cpus, $total_users_cpus;
    
    // PRINT TAIL
    $susp="";
    if ($total_suspended>0) 
         $susp="[".$total_suspended." suspended] ";
    
    echo shell_exec("printf '\n ".DARKRED."# ".DEFAULTC."Memory needed\n ".LIGHTRED."# ".DEFAULTC."Memory reserved\n ".LIGHTGREEN."# ".DEFAULTC."Memory available\n ".DARKBLUE."# ".DEFAULTC."Computer not available\n\n'");
    
    echo ("(".$total_running." cpu used ".$susp."out of ".$total_cpus.")\n");
    if (($total_running != $total_users_cpus) && ($total_users_cpus != 0))
    	// Si no tenen el mateis valor es per que ens demanat només veure els jobs de uns usuaris, no de tots. 
    	// En aquest cas ho mostrem
    	echo ("(".$total_users_cpus." cpu used by the users listed)\n");
    
    echo "\n";
    
}

// RETURN COLOR ACCORDING TO A SCALE FROM RED TO PURPLE TO YELLOW TO GREEN
function paint($variable, $final) {
	
	$color = DEFAULTC;
	
	if ($variable >= 0.86*$final) {
		$color = DARKRED;
	} else if ( $variable >= 0.72*$final ) {
		$color = LIGHTRED;
	} else if ( $variable >= 0.58*$final ) {
		$color = PURPLE;
	} else if ( $variable >= 0.44*$final ) {
		$color = DARKYELLOW;
	} else if ( $variable >= 0.30*$final ) {
		$color = LIGHTYELLOW;
	} else if ( $variable >= 0.16*$final ) {
		$color = DARKGREEN;
	} else if ( $variable < 0.16*$final ) {
		$color = LIGHTGREEN;
 	}
 	
	return $color;

}

// RETURN COLOR ACCORDING TO A SCALE FROM GREEN TO YELLOW TO PURPLE TO RED
function paint_reverse($variable, $final) {
	
	$color = DEFAULTC;
	
	if ($variable >=0.86*$final ) {
		$color = LIGHTGREEN;
	} else if ( $variable >= 0.72*$final ) {
		$color = DARKGREEN;
	} else if ( $variable >= 0.58*$final ) {
		$color = LIGHTYELLOW;
	} else if ( $variable >= 0.44*$final ) {
		$color = DARKYELLOW;
	} else if ( $variable >= 0.3*$final ) {
		$color = PURPLE;
	} else if ( $variable >= 0.16*$final ) {
		$color = LIGHTRED;
	} else if ( $variable < 0.16*$final ) {
		$color = DARKRED;
 	}
	return $color;

}

// Convertim a KB per poder fer operacions aritmétiques.
function convert_to_KB ($variable) {
	
	// Agafem el valor numéric
	$value=substr($variable, 0, -1);
	
	// En funcio de les unitat fem
	switch (substr($variable,-1)) {
		case 'm';
			$value=$value*1024;
			break;
		case 'M':
			$value=$value*1024;
			break;
		case 'g':
			$value=$value*1024*1024;
			break;
		case 'G':
			$value=$value*1024*1024;
			break;
		case 't':
			$value=$value*1024*1024*1024;
			break;
		case 'T':
			$value=$value*1024*1024*1024;
			break;
	}
		
	return $value;
}

// Com que no tenim garantitzar que Slurm ens retorna els valors de quantitat de memoria o disc
// en al unitat adecuada (ex: si son mes de 1024 MB q ens tornarà l'equivalent en Gb) fem aquesta
// funcio per assegurar-hos de que sigui així.
function fix_units ($variable) {

	// NOTA: Asumim que no tindrem  Pentabytes

	$variable_str="";
	
	$unit=substr($variable,-1);
	$num=substr($variable,0,-1);

	// Si el valor es 0, que les unitats siguin K"
	if ($num == "0") {
		$unit = "K";
	}

	// Faig un bucle per si em de saltar de més de una unitat
	// (ex: de KB em de passar a GB)
	while ($num >= 1024 && preg_match("/^[GgKkMmTt]$/",$unit) ) {

		// Primer arreglem el valor numéric
		$num=round($num/1024,1);

		// Canviem a la següent unitat
		switch ($unit) {
			case 'K':
			case 'k':
				$unit = "M";
				break;
			case 'M':
			case 'm':
				$unit = "G";
				break;
			case 'G':
			case 'g': 
				$unit = "T";
				break;
			case 'T':
			case 't':
				$unit = "P";
				break;
		}
				
	}

	return $num.$unit;

}

// ALIGN TEXT
function align ($variable, $long_max) {
	
	$var_str=$variable;
	for($i=strlen($var_str); $i <$long_max; $i++) {
		$var_str = " ".$var_str;
	}
	
	return $var_str;
	
}


function fill_jobs_info ($nodename, $jobs_info, $partition_list) {
    
    $count=0;           
    $job_array = array();
                
    foreach ($jobs_info as $job => $value) {
                    
        if (array_key_exists ($nodename, $value) && belong_to_partition($value['partition'],$partition_list)) {
                                
        		// Afegim tota la info que em guardat en el array temporal al definitiu

            $job_array[$job]['user'] = $value['userid'];            						// nom del usuari amb el uid
            $job_array[$job]['jobname'] = $value['jobname'];       						// nom del job
            $job_array[$job]['jobid'] = $job;            									// jobid
            $job_array[$job]['jobidu'] = $value[$nodename]['jobidu'];          		// unique job id (fa falta per els array jobs)
            $job_array[$job]['cpus'] = $value[$nodename]['cores'];         			// cpu en us per aquest job en aquest node
            $job_array[$job]['maxcpus'] = $value['cpu_total'];         				// número total de cpu que fa servir el job
            $job_array[$job]['start time'] = $value['starttime'];       				// data inici d'execució del job
            $job_array[$job]['partition'] = $value['partition'];            				// nom de la partició                        
            $job_array[$job]['status'] = 'running';         								// status. De moment aquest script nomes mostra running jobs.
            $job_array[$job]['cpu'] = $value[$nodename]['cputime'];                	// cpu usage. Useless.
            $job_array[$job]['mem reserved node'] = $value[$nodename]['req_mem'];  	// memòria demana en el node per el job
            $job_array[$job]['maxvmem node'] = $value[$nodename]['maxrss'];
            $job_array[$job]['gres'] = $value[$nodename]['gres'];
                        
        }
                    
    }
                 
    return $job_array;
}


function fill_computer_info ($nodename, $node_info) {
    
    //    $computer_info => $computes[$cmp]
    
    global $max_cpus_node;
    
    $computer_info['mem used'] = 0;    							// Memoria en us	--> QUE FEM AMB AIXO?? 
    $computer_info['mem reserved'] = $node_info['mem_used'];// Memoria demandas per els usuaris
    $computer_info['mem max'] = $node_info['mem_total'];    // Memoria del node
    $computer_info['jobs'] = $node_info['cpu_used'];        // CPU's en us
    $computer_info['maxjobs'] = $node_info['cpu_total'];    // CPU's del node
    $computer_info['partitions'] = $node_info['partitions'];// Particions on està present aquest node
    
    // Actualitzem si cal el valor del node que té mes cpu (es fa servir per formatejar en pantalla la visualització)
    if ($computer_info['maxjobs'] > $max_cpus_node )
        $max_cpus_node = $computer_info['maxjobs'];
    
    $computer_info['gres_av'] = $node_info['gres_av'];         // GRES del node
    $computer_info['gres_used'] = $node_info['gres_used'];
    
    return $computer_info; 
    
}


function extract_computers_info() {

// Aixo es el que hem dona el scontrol ... :

//    NodeName=mr-00-12
//    Gres=tmpdir:400G,gpu:kepler:4
//    GresUsed=gpu:kepler:4(IDX:0-3),tmpdir:0
//    CfgTRES=cpu=16,mem=116G
//    AllocTRES=cpu=2,mem=42000M

// Ho vull deixar així:

// Array
// (
//      [mr-00-12]  => Array
//            (
//                  [mem_used] => 42000M
//                  [mem_total] => 116G
//                  [cpu_used] => 2
//                  [cpu_total] => 16
//                  [gres_av] => Array
//                          (
//                              [gpu] => 4 
//                              [tmpdir] => 400G
//                            )
//                  [gres_used] => Array
//                            (
//                                [gpu] => 2
//                                [tmpdir] => 0
//      
    
    global $gres;
    
    $cmp=array();
    
    exec ("scontrol show nodes --oneliner --detail | sed 's/\\s/\\n/g' | grep -e \"NodeName=\" -e \"Gres=\" -e \"GresUsed\" -e \"CfgTRES=\" -e \"AllocTRES=\" -e \"Partitions=\" ", $exec_nodes);
  
    for ($i=0; $i < count($exec_nodes); $i++) {
        
      // No faig servir el preg_match per que si hi ha mes de un igual, no se per que, talla per la última ocurrencia i jo vull
      // que ho faci per la primera.
      
      $matches=explode ("=", $exec_nodes[$i], 2);
      
      switch ($matches[0]) {
          case "NodeName":
          
              $node_name=$matches[1];
              
              break;
          case "Partitions":
          
				  $cmp[$node_name]["partitions"] = $matches[1];        
          
          	  break;
          case "Gres":      // tmpdir:400G,gpu:kepler:4
          
              // Preparo l'array on guardare els gres diponibles en el node. Aprofito i també inicialitzo els gres en us ja que es possible
              // que no surti info de GresUsed si no hi han.
              $cmp[$node_name]["gres_av"] = $gres;
              $cmp[$node_name]["gres_used"] = $gres;
              
              
              // Inicialitzem l'array per no tindre valors nulls (amb el gres in use no fa falta).
              foreach ($cmp[$node_name]["gres_av"] as $key => $value)
                  $cmp[$node_name]["gres_av"][$key] = 0;
              
              if (count($gres) > 0) {
                    // La llista de recursos gres NO es buida, so, hem de fer el tractament dels gres disponibles a la máquina. 
                    $node_gres = explode (",", $matches[1]);

                    // Com que podem tindre varis gres definits al cluster i no sabem quants, fem un bucle per tractar-los.
                    for ($j=0; $j < count($node_gres); $j++) {
                        unset($item_gres);
                 
                        preg_match("/.+(:.+)+/", $node_gres[$j], $item_gres);
                        $item_gres = explode (":", $node_gres[$j]);
                        if (array_key_exists ($item_gres[0], $gres))
                            $cmp[$node_name]["gres_av"][$item_gres[0]] = end($item_gres);
                    }
              }
              
              break;
              
          case "GresUsed":  //  gpu:kepler:4(IDX:0-3),tmpdir:0
          
              // Preparo l'array on guardare els gres en us en el node
              $cmp[$node_name]["gres_used"] = $gres;
              
              if (count($gres) > 0) {
                  // La llista de recursos gres NO es buida, so, hem de fer el tractament dels gres en us a la máquina.
                  $gres_used = explode (",", $matches[1]);

                  // Com que podem tindre varis gres definits al cluster i no sabem quants, fem un bucle per tractar-los.
                  for ($j=0; $j < count($gres_used); $j++) {
                      unset($item_gres);

                      // Trec la part on identifica el recurs (IDX:...)
                      $no_idx = explode("(", $gres_used[$j]);
                      $item_gres = explode(":", $no_idx[0]);
                      if (array_key_exists ($item_gres[0], $gres)) 
                          // Hem quedo amb el número de items en us d'aquest recurs.
                          if ( has_units($cmp[$node_name]["gres_av"][$item_gres[0]]) && ! has_units(end($item_gres)) ) {
                              // La definició d'aquest recurs té unitats (KB, MB, ...), so, mirem que les unitats sortin bé. Nota: se que
                              // per l'orde d'aparicio de les dades, els Gres available ja han sigut tractats.
                              // Asumeixo que ho retorna en Bytes, pero com que aixó son gres, no se si sempre es així amb tots els gres amb
                              // unitats o si depen de com estigui definit, en el cas de les probes que vaig fer, ho tornava sense unitats
                              // i en Bytes.
                              $num = end($item_gres);
                              $num_in_kb = $num/(1024);
                              $cmp[$node_name]["gres_used"][$item_gres[0]] = fix_units($num_in_kb."K");
                          } else
                              $cmp[$node_name]["gres_used"][$item_gres[0]] = end($item_gres);
                  }
              }
              
              break;
              
          case "CfgTRES":  // cpu=16,mem=116G 
          
				  $tres=explode (",", $matches[1]);
              // Agafo el num de cpu
              $tcpu = explode("=", $tres[0]);
              $cmp[$node_name]["cpu_total"]=$tcpu[1];
              
              // Agafo la memoria total
              $tmem = explode("=", $tres[1]);
              $cmp[$node_name]["mem_total"]=fix_units($tmem[1]);
              
              break;
          case "AllocTRES":     // cpu=2,mem=42000M 
              
              if ($matches[1] == "") {
                  // No hi han TRES en us, es a dir, el node no té cap job en RUN o está DOWN
                  $cmp[$node_name]["cpu_used"]=0;
                  $cmp[$node_name]["mem_used"]=0;
                  
              } else {
 
                    $used_tres=explode (",", $matches[1]);
                    
                    // Agafo el num de cpu en us
                    $ucpu = explode("=", $used_tres[0]);
                    $cmp[$node_name]["cpu_used"]=$ucpu[1];
              
                    // Agafo el memoria en us
                    $umem = explode("=", $used_tres[1]);
                    $cmp[$node_name]["mem_used"]=fix_units($umem[1]);
              }
              
              break;
      }     
      
    }

    return $cmp;
}

function has_units($value) {
    
    // Si te unitats ha de ser en formar <numero><[K|M|G|T]>
    
    $unit=substr($value,-1);
    $num=substr($value,0,-1);
    
    if (preg_match("/[0-9]+/", $num) && preg_match("/^[GgKkMmTt]$/",$unit))
        return true;
    else
        
        return false;
    
}

function extract_jobs_info () {

    /*
    Ha de tornar una estructura de dades amb els recursos fets servir per els jobid en cada node partint de la 
    info obtenida del exec("scontrol show jobs ...") i del exec("sudo sstat --format=\"JobID,M...") amb els rangs 
    dels nodes desfets (si tenim un mr-00-[01-04] que sigui mr-00-01, mr-00-02, mr-00-03, mr-00-04):
    
    exec("scontrol show jobs --oneliner --detail | grep \"JobState=RUNNING\" | sed 's/\\s/\\n/g' | grep -e \"JobId\" -e \"NumNodes\" -e \"ArrayJobId\" -e \"ArrayTaskId\" -e \"JobName\" -e \"UserId\" -e \"SubmitTime\" -e \"Partition\" -e \"^Nodes=\" -e \"CPU_IDs\" -e \"Mem=\" -e \"Gres=\" ", $exec_cons_rangs);

	 Un cop desfet els rangs de la execució anterior hem de tindre mes o menys aixó:
	 
Array
(
    [0] => JobId=507444
    [1] => JobName=clumpp
    [2] => UserId=sbiagini(1271)
    [3] => StartTime=2017-12-10T20:20:07
    [4] => Partition=normal
    [5] => NumNodes=2
    [6] => TRES=cpu=4,mem=16000M,node=2
    [7] => Nodes=mr-00-06
    [8] => CPU_IDs=8-9
    [9] => Mem=8000
    [10] => Nodes=mr-00-15
    [11] => CPU_IDs=8,12
    [12] => Mem=8000
    [13] => Gres=(null)
    [14] => JobId=507445
    [15] => JobName=clumpp
    [16] => UserId=sbiagini(1271)
    [17] => StartTime=2018-01-03T17:15:56
    [18] => Partition=normal
    [19] => NumNodes=2
    [20] => TRES=cpu=4,mem=16000M,node=2
    [21] => Nodes=mr-00-02
    [22] => CPU_IDs=9,11
    [23] => Mem=8000
    [24] => Nodes=mr-00-03
    [25] => CPU_IDs=9-10
    [26] => Mem=8000
    [27] => Gres=(null)
    [28] => JobId=510655
    [29] => JobName=Kpostx0
    [30] => UserId=msantama(5141)
    [31] => StartTime=2018-01-23T15:50:19
    [32] => Partition=normal
    [33] => NumNodes=2
    [34] => TRES=cpu=4,mem=80000M,node=2
    [35] => Nodes=mr-00-16
    [36] => CPU_IDs=3-5
    ...
    ...
    ...
    
    
    
    I a la sortida d'aquesta funció hem de tindre tota aquesta info en aquesta estructura
    de dades: 


Array
(
    [507444] => Array
        (
            [jobname] => clumpp
            [userid] => sbiagini
            [starttime] => 2017/12/10 20:20:07
            [partition] => normal
            [numnodes] => 2
            [mr-00-06] => Array
                (
                    [cores] => 2
                    [req_mem] => 7.8G
                    [gres] => Array
                        (
                            [gpu] => 0
                            [tmpdir] => 0
                        )

                    [jobidu] => 507444
                    [maxrss] => 2.5M
                    [cputime] => 210-08:27:04
                )

            [mr-00-15] => Array
                (
                    [cores] => 2
                    [req_mem] => 7.8G
                    [gres] => Array
                        (
                            [gpu] => 0
                            [tmpdir] => 0
                        )

                    [jobidu] => 507444
                    [maxrss] => 0
                    [cputime] => 210-08:27:04
                )
                [cpu_total] => 4
                [mem_total] => 15.6G
        )
        [507445] => Array
        (
            [jobname] => clumpp
            [userid] => sbiagini
            [starttime] => 2018/01/03 17:15:56
            [partition] => normal
            [numnodes] => 2
            [mr-00-02] => Array
                (
                    [cores] => 2
                    [req_mem] => 7.8G
                    [gres] => Array
                        (
                            [gpu] => 0
                            [tmpdir] => 0
                        )

                    [jobidu] => 507445
                    [maxrss] => 194.9M
                    [cputime] => 114-20:43:48
                )

            [mr-00-03] => Array
                (
                    [cores] => 2
                    [req_mem] => 7.8G
                    [gres] => Array
                        (
                            [gpu] => 0
                            [tmpdir] => 0
                        )

                    [jobidu] => 507445
                    [maxrss] => 0
                    [cputime] => 114-20:43:48
                )

            [cpu_total] => 4
            [mem_total] => 15.6G
        )
	     ...
	     ...
	     [515676] => Array
        (
            [jobname] => Kpost8dicx0a50
            [userid] => msantama
            [starttime] => 2018/01/07 22:53:23
            [partition] => normal
            [numnodes] => 1
            [mr-00-01] => Array
                (
                    [cores] => 4
                    [req_mem] => 39.1G
                    [gres] => Array
                        (
                            [gpu] => 0
                            [tmpdir] => 0
                        )

                    [jobidu] => 515676
                    [maxrss] => 2.2G
                    [cputime] => 0
                )

            [cpu_total] => 4
            [mem_total] => 39.1G
        )
		  ...
		  ...
		  ...


    */
    /* NOTA sobre array jobs i paral.lel jobs:
     * - Els parallel jobs assumo que son jobs entre ells 'independents' (son varis jobs clonics i cada un s'executa en un node), 
     *   per aquesta raó faig que el jobid de cadascú sigui el <arrayjobid>_<arraytaskid> (no jaig servir el camp jobidu (unique jobid)
     * - El paral·lel jobs son el mateix job que s'executa en varis nodes (poder cada instancia executa una part del codi o tasca diferent),
     *   aquí si que tots tenen el mateix jobid (per que son el mateix job) pero els distingueixo amb el jobidu (unique jobid)
     */

    
    /*
     * squeue -t r -o "%i" -h | sed 's/$/.batch/' | sed ':a;N;$!ba;s/\n/,/g' --> afegir .batch a tots els jobs en run i fer-ho comma separated
     * squeue -t r -o "%i" -h  | sed ':a;N;$!ba;s/\n/,/g' -> canviar els salts de linea per una coma (fer una llista comma separated)
     * squeue -t r -o "%i" -h | sed 'h;G;s/\n/.batch,/' -> dobla cada linea y al pirmer valor le apade '.batch,'
     * squeue -t r -o "%i" -h | sed 'h;G;s/\n/.batch,/' | sed ':a;N;$!ba;s/\n/,/g' -> lo que quiero con dos seds
     * sudo sstat --format="JobID,MaxRSS,MaxRSSNode" -a -n -P -j <comma-separated-jobid>
     * sudo sstat --format="JobID,MaxRSS,MaxRSSNode" -a -n -P -j `squeue -t r -o "%i" -h | sed 'h;G;s/\n/.batch,/' | sed ':a;N;$!ba;s/\n/,/g'`
     * 
     */ 
    
   global $gres;
    
	$var_jobs=array();	

	// Agafem la info que ens interesa de tots els jobs que están en RUNNING en el cluster.    
   exec("scontrol show jobs --oneliner --detail | grep \"JobState=RUNNING\" | sed 's/\\s/\\n/g' | grep -e \"JobId\" -e \"NumNodes\" -e \"ArrayJobId\" -e \"ArrayTaskId\" -e \"JobName\" -e \"UserId\" -e \"StartTime\" -e \"Partition\" -e \"^Nodes=\" -e \"CPU_IDs\" -e \"Mem=\" -e \"Gres=\" -e \"TRES=\" ", $exec_cons_rangs);	  
    
   // Si l'array no està buit es per que hi han jobs en execució. Si está buit, retornem l'array buit.
	if (!empty ($exec_cons_rangs)) {
        
    	

		// Abans de tot hem de desfer els rangs de nodes (mr-00-[01-05])de la info obtinguda de exec anterior.
    	$exec_cons = desfer_rangs ($exec_cons_rangs);
    
    	// Faig servir el %A i no el %i per que es un jobid unique que hem va millor per el sstat si tenim array jobs (provar amb squeu -o "%i %A" per veure-ho)
    	exec("sudo sstat --format=\"JobID,MaxRSS,MaxRSSNode\" -a -n -P -j `squeue -t r -o \"%A\" -h | sed 'h;G;s/\\n/.batch,/' | sed ':a;N;$!ba;s/\\n/,/g'`", $exec_sstatjobs); 
 
    	// Dono al resultat exec el format que m'interessa per poder obtendre de una manera cómoda la info del MaxRSS.
    	// Començo per el final per detectar el job.batch de un parallel job que no vull que estigui en el resultat final.
    	$last_jobid="";
    	for ($i = (count($exec_sstatjobs) - 1); $i >= 0; $i--) {
        	$line = explode("|",$exec_sstatjobs[$i]);
        	$only_jobid=substr($line[0],0,strrpos($line[0], "."));
        	// Miro d'esquivar els <jobid>.batch dels parallel jobs, no el vull al array de sortida.
        	if ($line[0]!=$last_jobid.".batch") {
            $maxrss_info[$line[2]][$only_jobid]['jobidu']=$line[0];
            $maxrss_info[$line[2]][$only_jobid]['maxrss']=fix_units($line[1]);
        	}
        	$last_jobid=$only_jobid;
    	}   

    	// Obtinc la llista de tots els CPUTime ...
    	exec("sacct --format=\"CPUTime,JobIDRaw,State\" -a -n -P | grep \"RUNNING\"", $exec_cputime);
    	// ... i arreglo la llista per poder treure la info més facilment.    	
    	
    	for ($i=0; $i < count($exec_cputime); $i++) {
        	$line = explode("|",$exec_cputime[$i]);
        	$cputime_info[$line[1]]['cputime']=$line[0];
    	}
    
		for ($i = 0; $i < count($exec_cons); ++$i) {
		
			$matches=explode ("=", $exec_cons[$i], 2);

			switch ($matches[0]) {
		    	case "JobId":
		          // Estem a la linea del jobid que es on comença cada bloc.
		          
		          $job_id="";
		          // Mirem si es un array job
		          if ( preg_match("/ArrayJobId=(.+)/",$exec_cons[$i+1],$arrayjobid_matches)) {
		            
		              // Es array job, construïm el jobid en format <arrayjobid>_<arraytaskid>
		              preg_match("/ArrayTaskId=(.+)/",$exec_cons[$i+2],$arraytaskid_matches);
		              $job_id=$arrayjobid_matches[1]."_".$arraytaskid_matches[1];
		              $i=$i+2;
		          } else {
		              $job_id=$matches[1];
		          }	
		          
		          break;
		    	case "JobName":
		          $var_jobs[$job_id]['jobname']=$matches[1];
		          
		          break;
		    	case "UserId":
		          // Hem quedo només amb el username, no vull el uid
		          preg_match("/(.+)\([0-9]+\)/",$matches[1],$user_info);
		          $var_jobs[$job_id]['userid']=$user_info[1];
		          
                  break;
		    	case "StartTime":
		          // Deixo el format de dia i hora tal i com ho espera trobar tota la part de printing.
		          $matches[1] = str_replace('T', ' ', $matches[1]);
		          $matches[1] = str_replace('-', '/', $matches[1]); 
		          $var_jobs[$job_id]['starttime']=$matches[1];
		          
		          break;
		    	case "Partition":
		          $var_jobs[$job_id]['partition']=$matches[1];
		          
		          break;
		    	case "TRES":      // $matches[1]= cpu=4,mem=80000M,node=2
		          $tres=explode (",", $matches[1]); 
		          // Agafo el num de cpu
		          $tcpu = explode("=", $tres[0]);
		          $var_jobs[$job_id]['cpu_total']=$tcpu[1];
		        
		          // Agafo la memoria total
		          $tmem = explode("=", $tres[1]);
		          $var_jobs[$job_id]['mem_total']=fix_units($tmem[1]);
		          
		          // Atenció: com que se que les lineas de codi que continuen a aquesta son les tractades per el cas NumNodes, les salto
		          // totes per anar a la següent de JobId. Aixo passa per que en la variable $exec_cons (resultat de un scontrol) tenim el
		          // valor de TRES per sota del de NumNodes. El valor de $k ve donat del pas de l'execució per el 'case "NumNodes":'
		          $i = ($i+1)+$k;
		          
		          break;
		    	case "NumNodes":

                  $var_jobs[$job_id]['numnodes']=$matches[1];
                    
		          // Agafo ja el GRES que será comú per tots els nodes en cas de que sigu un rang de nodes.
		          // A $req_gres tindrem el GRES demanat que s'agafara a cada node.
		          // $k apunta al final del bloc de nodes.
		          // $matches[1] te el numero de nodes on está el job en execució.
		          $k=3*$matches[1];
		          // El $i+2 es per salta la linea amb la info de TRES que está entre NumNodes i la info del primer node.
		          preg_match("/Gres=(.+)/",$exec_cons[($i+2)+$k],$gres_matches);
		        
		          $req_gres = $gres;
		          
		          // Inicialitzem l'array per no tindre valors nulls (amb el gres in use no fa falta).
		          foreach ($req_gres as $key => $value)
		              $req_gres[$key] = 0;
		              
                if ( isset($gres_matches[1]) ) {
		              // El job està fent servir algún GRES.
		              $gres_list = explode(",",$gres_matches[1]);
		              foreach ($gres_list as $gres_item) {
		                  $item = explode(":",$gres_item);
		                  // Nomes hem quedo amb els GRES que m'han demanat visualitzar.
		                  if (array_key_exists($item[0],$gres))
		                      $req_gres[$item[0]]=$item[1];
		              }
		          }
		        
		          // Explorem tots els nodes on está en execució un jobid.
		          for ($j = 0; $j < $matches[1]; $j++) {
		            
		              // Calculem la posició absoluta on estem dintre del array $exec_cons. Com abans, el $i+2 es per
		              // salta la linea TRES que está entre la linea que conté el NumNodes i la info del primer node
		              $pos=($i+2)+($j*3);	              
		              
		              // Agafem el nom del node/s.
		              preg_match("/Nodes=(.+)/",$exec_cons[$pos],$nodes_matches);
		              // Atencio, "Nodes=" por ser una llista
		              $list_nodes = explode (",", $nodes_matches[1]);
		              		            
		              // Mirem el número de CPU's que fa servir el job en aquest node.
		              preg_match("/CPU_IDs=(.+)/",$exec_cons[$pos+1],$cpu_matches);
		            
		              $ncore = 0;
		              // Podem tindre varis rangs de cpu separats per coma, per aixó el següent 'explode'
		              // mes el bucle 'foreach'. Si només un rang o core entrem un cop només.
		              $cores_range = explode (",", $cpu_matches[1]);
		              foreach ($cores_range as $cores) {
		                  if ( preg_match("/([0-9]+)-([0-9]+)/",$cores,$value)) 	                      // Aquest element es un rang de cores (ex: 1-5)
		                      $ncore=$ncore+($value[2]-$value[1]+1);
		                  else 
		                      // Es un integer únic, so, this jobid is using one core
		                      $ncore++;
		              }
		            
		              // Agafem la memoria que s'ha demanat per el job (ens ho diu en megabytes).
		              preg_match("/Mem=(.+)/",$exec_cons[$pos+2],$mem_matches);
		              $req_mem=$mem_matches[1]."M";
		              $req_mem=fix_units($req_mem);
		            
		              // Escribim la info en el array de output. Hem de tindre en compte que poder es mes de un node.
		              foreach ($list_nodes as $node_name) {
		                  $var_jobs[$job_id][$node_name]['cores']=$ncore;
		                  $var_jobs[$job_id][$node_name]['req_mem']=$req_mem;
		                  $var_jobs[$job_id][$node_name]['gres']=$req_gres;
		              }
		              
		              // Atencio, si en $list_nodes hi ha mes de un node hem de fer correr el index $j (ell de per si, al ser un
		              // foreach corre una posició en cada loop, pero en aquest cas ha de corre tantes posicions com elements tinguen
		              // a la $list_nodes).
		              $j += (count($list_nodes) - 1);
		            
	                 // M'aseguro que per alguna rao, en el jobid no hi hagi la paraula .batch (pot pasar per exemple, 
	                 // amb un mal us de un parallel job).
		                  
	                 // Per culpa del que crec que es un mal us, també pot ser que un job faci servir més de un node pero
	                 // que el sstat només tingui info del us de un d'ells (el job demana més de un node pero no els fa servir)
	                 // Aixó vol dir que no existirá la referencia d'aquest job en el node dintre del array $maxrss_info, ho tinc
	                 // que control·lar per que no aparegui per pantalla un error de php:
		                  
	                 // Un altre cop, hem de tindre en compre que pode a $list_nodes hi ha mes de un node:
	                 foreach ($list_nodes as $node_name) {
   		                  if (array_key_exists($node_name, $maxrss_info)){
   		                      // OK, aquest node te jobs en execucio, per aixó está en el array $maxrss_info
    		                  
   		                      if (array_key_exists($job_id, $maxrss_info[$node_name])) {
   		                          // I un dels jobs en execucio es el $job_id:    
   		                          $only_jobid = explode (".batch", $maxrss_info[$node_name][$job_id]['jobidu']);
   		                          $var_jobs[$job_id][$node_name]['jobidu']=$only_jobid[0];
   		                      } else
   		                          // El node té jobs en execució pero cap es el que estem tractant, es a dir, l'usuari a demanat
   		                          // més de un node pero aques node no l'està fent servir:
   		                          $var_jobs[$job_id][$node_name]['jobidu']=$job_id;
   		                  } else
   		                      // Si el node no te cap job en execucio vol dir que l'usuari a demanat fer servir més de un node, te 
   		                      // aquest assignat pero no l'està fent servir.
   		                      $var_jobs[$job_id][$node_name]['jobidu']=$job_id;
		                    
    		              		// Guardo el maxRSS fet servir per el job. (S'ha de tindre en compte l'explicació del comentari anterior sobre quan
    		              		// un usuari demana mes de un node pero no els fa servir !!)
    		              		if (array_key_exists($node_name, $maxrss_info)) {
		                  
		                     	if (array_key_exists($job_id, $maxrss_info[$node_name]))
    		                      	$var_jobs[$job_id][$node_name]['maxrss']=$maxrss_info[$node_name][$job_id]['maxrss'];
	       	                  else
		                         	$var_jobs[$job_id][$node_name]['maxrss']="0";
		                  	} else
		                     	$var_jobs[$job_id][$node_name]['maxrss']="0";
		                    
    		              		// Guardo el CPUTime
    		              		// Aixo es una chorrada, únicament fico aquí el jobidu per que la segona instrucció sigui més comprensible.
    		              		$unique_jobid = $var_jobs[$job_id][$node_name]['jobidu'];
	       	              	if (array_key_exists ($unique_jobid, $cputime_info))
    	                     	// Per culpa del que cre que es un mal us, també pot ser que un job faci servir més de un node pero
    		                  	// que el sacct només tingui info del us de un d'ells (el job demana més de un node pero no els fa servir)
    		                  	// Aixó vol dir que no existirá la referencia d'aquest job en el node dintre del array $cputime_info, ho tinc
    		                  	// que control·lar per que no apareguiper pantalla un error de php.
    		                  	$var_jobs[$job_id][$node_name]['cputime']=$cputime_info[$unique_jobid]['cputime'];
    		              		else 
    		                  	$var_jobs[$job_id][$node_name]['cputime']="0";
		              }  
		                    
	   	       }
		          
		          // Com hem explorat el valor de array actual (Nodes=) mes el següent (els cores) hem
		          // de salta una linea.
		          // $i = ($i+2)+$k;
		        
		          break;
			}
				
		}

	}

	return $var_jobs;
	
}

function desfer_rangs ($entrada) {
    
    $sortida=array();
    
    for ($i = 0; $i < count($entrada); $i++) {    
    
        if (preg_match("/Nodes=(.+)/",$entrada[$i],$matches)) {
            if ( preg_match("/(.+)\[([0-9]+)-([0-9]+)\]/",$matches[1],$nodesindex_matches)) {
                // Hem trobat un rang de Nodes, l'hem de desfer
                
                // Agafem el valor de CPU_IDs i Mem del rang dels nodes.
                $cpu_id=$entrada[$i+1];
                $mem=$entrada[$i+2];
                
                // Ho necesitem per mirar de que el numero de node tingui sempre el mateix nombre de digits
                // ex: que sigui 03 i no 3
                $digits=strlen((string) $nodesindex_matches[2]);
                for ($j = $nodesindex_matches[2]; $j <= $nodesindex_matches[3]; ++$j) {
                    
                    // Aixo es per que el indes $j sigui '0X' i no només 'X' (per el nom dels nodes es important).
                    $id_node = sprintf("%0".$digits."d", $j);
                    
                    $computer="Nodes=$nodesindex_matches[1]$id_node";
                    array_push($sortida,$computer,$cpu_id,$mem);
                }
                // Saltem les linies que ens indicaven el CPU_IDs i Mem per el rang de nodes.
                $i = $i + 2; 
            
            } else
                // No hi havia un rang
                array_push($sortida, $entrada[$i]);
        } else 
            // Afegim el valor al array de sortida    
            array_push($sortida, $entrada[$i]);
    }

    return $sortida;
}

function sum_mem_used (&$computes) {
	
	foreach	($computes as $key => $value) {
		
		if (! empty($value['job_array'])) {
			$mem_used = 0;
			foreach ($value['job_array'] as $job) 
				$mem_used += convert_to_KB($job['maxvmem node']);
			
			$computes[$key]['mem used'] =  fix_units($mem_used."K");

		
		}
			
	}


}


?>
