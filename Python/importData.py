import pandas as pd
import mysql.connector
from datetime import datetime
import re  # Add this at the top of the file with other imports


def read_meteo_stations_data(excel_file):
    """Read meteorological stations data from Excel and generate SQL insert commands"""
    # Read all sheets
    excel = pd.ExcelFile(excel_file)
    sheet_names = excel.sheet_names[1:]  # Skip first sheet
    sql_commands = []
    
    # Create a set for unique station names
    unique_stations = set()
    
    # First pass: collect unique station names
    for sheet_name in sheet_names:
        df = pd.read_excel(excel_file, sheet_name=sheet_name, header=None)
        station_name = re.sub(r'\s+', ' ', str(df.iloc[0, 0])).strip()
        unique_stations.add(station_name)
    
    # Generate INSERT statements for unique stations
    for station_name in unique_stations:
        sql = f"""
        INSERT INTO tree_db.meteo_station (
            name
        )
        VALUES (
            '{station_name}'
        );
        """
        sql_commands.append(sql)
    
    # Append Copernicus data station
    sql = f"""
    INSERT INTO tree_db.meteo_station (
        name
    )
    VALUES ('Copernicus');
    """
    sql_commands.append(sql)

    print(f"Found {len(unique_stations)} unique meteorological stations")
    return sql_commands

def read_pollution_data(excel_file):
    """Read pollution data from Excel and generate SQL insert commands"""
    # Read all sheets
    excel = pd.ExcelFile(excel_file)
    sheet_names = excel.sheet_names
    
    # Skip only the first sheet
    relevant_sheets = sheet_names[1:]
    sql_commands = []
    
    # Add these day name mappings at the start of the function
    day_names = {
        0: 'Mon',
        1: 'Tue',
        2: 'Wed',
        3: 'Thu',
        4: 'Fri',
        5: 'Sat',
        6: 'Sun'
    }
    
    for sheet_name in relevant_sheets:
        # Read Excel without headers
        df = pd.read_excel(excel_file, sheet_name=sheet_name, header=None)
        
        # Get location name from the first row, first column and clean it
        location_name = re.sub(r'\s+', ' ', str(df.iloc[0, 0])).strip()
        
        # Skip the first row as it's usually a title
        df = df.iloc[1:]
        
        # Reset index to make sure we can access the rows properly
        df = df.reset_index(drop=True)
        
        for index, row in df.iterrows():
            try:
                # Skip row if date (column C) is empty
                if pd.isna(row[2]):
                    continue
                
                # Get values by column position
                # B column (index 1) = A.A.
                # C column (index 2) = Date
                # D column (index 3) = Day
                # E column (index 4) = SO2
                # F column (index 5) = PM10
                # G column (index 6) = PM2.5
                # H column (index 7) = CO
                # I column (index 8) = NO
                # J column (index 9) = NO2
                # K column (index 10) = O3
                # Last two columns = temperature and humidity
                
                # Convert date string to datetime with flexible format
                date_str = str(row[2])  # C column
                try:
                    # Try first format (dd/mm/yy)
                    date_obj = pd.to_datetime(date_str, format='%d/%m/%y')
                except:
                    try:
                        # Try second format (yyyy-mm-dd)
                        date_obj = pd.to_datetime(date_str)
                    except:
                        print(f"Could not parse date: {date_str} in row {index}")
                        continue
                
                # Get day name from date_obj
                day_of_week = day_names[date_obj.weekday()]
                year = date_obj.year
                
                # Get values by column position with better NULL handling
                values = {
                    'SO2': row[4] if pd.notna(row[4]) and str(row[4]).strip() != '' else 'NULL',        # E column
                    'PM10': row[5] if pd.notna(row[5]) and str(row[5]).strip() != '' else 'NULL',       # F column
                    'PM2.5': row[6] if pd.notna(row[6]) and str(row[6]).strip() != '' else 'NULL',      # G column
                    'CO': row[7] if pd.notna(row[7]) and str(row[7]).strip() != '' else 'NULL',         # H column
                    'NO': row[8] if pd.notna(row[8]) and str(row[8]).strip() != '' else 'NULL',         # I column
                    'NO2': row[9] if pd.notna(row[9]) and str(row[9]).strip() != '' else 'NULL',        # J column
                    'O3': row[10] if pd.notna(row[10]) and str(row[10]).strip() != '' else 'NULL',      # K column
                    'temperature': row[12] if pd.notna(row[12]) and str(row[12]).strip() != '' else 'NULL',  # M column
                    'humidity': row[13] if pd.notna(row[13]) and str(row[13]).strip() != '' else 'NULL'      # N column
                }
                
                sql = f"""
                SET @station_id = (
                    SELECT id FROM tree_db.meteo_station 
                    WHERE name = '{location_name}' 
                    LIMIT 1
                );
                INSERT INTO tree_db.polution (
                    number, station_id, date, datetime, day, year,
                    so2, pm10, pm25, co, no, no2, o3,
                    temperature, humidity
                )
                VALUES (
                    {row[1]},
                    @station_id,
                    '{date_obj.strftime('%Y-%m-%d')}',
                    '{date_obj.strftime('%Y-%m-%d')} 00:00:00',
                    '{day_of_week}',
                    {year},
                    {values['SO2']},
                    {values['PM10']},
                    {values['PM2.5']},
                    {values['CO']},
                    {values['NO']},
                    {values['NO2']},
                    {values['O3']},
                    {values['temperature']},
                    {values['humidity']}
                );
                """
                sql_commands.append(sql)
                
            except Exception as e:
                print(f"Error processing row {index} in sheet {sheet_name}: {e}")
                print(f"Row data: {row}")
                continue
    
    return sql_commands

def read_pollution_copernicus_data(csv_file):
    """Read pollution data from Copernicus CSV and generate SQL insert commands"""
    # Read CSV with headers since column names are needed
    df = pd.read_csv(csv_file)
    sql_commands = []
    
    # Add these day name mappings
    day_names = {
        0: 'Mon',
        1: 'Tue',
        2: 'Wed',
        3: 'Thu',
        4: 'Fri',
        5: 'Sat',
        6: 'Sun'
    }
    
    for index, row in df.iterrows():
        try:
            # Convert time string to datetime
            date_obj = pd.to_datetime(row['time'])
            
            # Get day name and year
            day_of_week = day_names[date_obj.weekday()]
            year = date_obj.year
            
            # Handle values with better NULL handling
            values = {
                'SO2': row['so2_conc'] if pd.notna(row['so2_conc']) and str(row['so2_conc']).strip() != '' else 'NULL',
                'NO': row['no_conc'] if pd.notna(row['no_conc']) and str(row['no_conc']).strip() != '' else 'NULL',
                'NO2': row['no2_conc'] if pd.notna(row['no2_conc']) and str(row['no2_conc']).strip() != '' else 'NULL',
                'O3': row['o3_conc'] if pd.notna(row['o3_conc']) and str(row['o3_conc']).strip() != '' else 'NULL',
                'CO': row['co_conc'] if pd.notna(row['co_conc']) and str(row['co_conc']).strip() != '' else 'NULL',
            }
            
            sql = f"""
            SET @station_id = (
                SELECT id FROM tree_db.meteo_station 
                WHERE name = 'Copernicus'
                LIMIT 1
            );
            INSERT INTO tree_db.polution (
                number, station_id, date, datetime, day, year,
                so2, co, no, no2, o3,
                temperature, humidity, pm10, pm25
            )
            VALUES (
                {index + 1},
                @station_id,
                '{date_obj.strftime('%Y-%m-%d')}',
                '{date_obj.strftime('%Y-%m-%d %H:%M:%S')}',
                '{day_of_week}',
                {year},
                {values['SO2']},
                {values['CO']},
                {values['NO']},
                {values['NO2']},
                {values['O3']},
                NULL,
                NULL,
                NULL,
                NULL
            );
            """
            sql_commands.append(sql)
            
        except Exception as e:
            print(f"Error processing row {index}: {e}")
            print(f"Row data: {row}")
            continue
    
    return sql_commands

def read_tree_types_data(csv_file):
    """Read tree types data from CSV and generate SQL insert commands"""
    # Read CSV without headers
    df = pd.read_csv(csv_file, header=None)
    sql_commands = []
    
    for index, row in df.iterrows():
        try:
            # Skip header row
            if index == 0:
                continue
                
            # Clean string values and handle NULL values
            # Column positions:
            # 0: type_id
            # 1: greek_name
            # 2: scientific_name
            # 3-8: md1-md6
            # 9: total
            # 10: area_m2
            # 11: crown_volume_m3
            # 12: avg_crown_volume_m3
            
            greek_name = re.sub(r'\s+', ' ', str(row[1])).strip() if pd.notna(row[1]) else ''
            scientific_name = re.sub(r'\s+', ' ', str(row[2])).strip() if pd.notna(row[2]) else ''
            
            # Handle numeric values
            values = {
                # 'md1': row[3] if pd.notna(row[3]) and str(row[3]).strip() != '' else 'NULL',
                # 'md2': row[4] if pd.notna(row[4]) and str(row[4]).strip() != '' else 'NULL',
                'md3': row[5] if pd.notna(row[5]) and str(row[5]).strip() != '' else '0',
                # 'md4': row[6] if pd.notna(row[6]) and str(row[6]).strip() != '' else 'NULL',
                # 'md5': row[7] if pd.notna(row[7]) and str(row[7]).strip() != '' else 'NULL',
                # 'md6': row[8] if pd.notna(row[8]) and str(row[8]).strip() != '' else 'NULL',
                # 'total': row[9] if pd.notna(row[9]) and str(row[9]).strip() != '' else 'NULL',
                # 'area_m2': row[10] if pd.notna(row[10]) and str(row[10]).strip() != '' else 'NULL',
                # 'crown_volume_m3': row[11] if pd.notna(row[11]) and str(row[11]).strip() != '' else 'NULL',
                # 'avg_crown_volume_m3': row[12] if pd.notna(row[12]) and str(row[12]).strip() != '' else 'NULL'
            }
            
            sql = f"""
            INSERT INTO tree_db.tree_type (
                type_id, greek_name, scientific_name, 
                amount
            )
            VALUES (
                {row[0]},
                '{greek_name}',
                '{scientific_name}',
                {values['md3']}
            );
            """
            sql_commands.append(sql)
            
        except Exception as e:
            print(f"Error processing row {index}: {e}")
            print(f"Row data: {row}")
            continue
    
    return sql_commands


def read_locations_data(excel_file):
    """Read locations data from Excel and generate SQL insert commands"""
    # Read Excel without headers
    df = pd.read_excel(excel_file, header=None)
    sql_commands = []
    
    # Skip header row
    df = df.iloc[1:]
    
    # Create a set of unique locations using relevant columns
    unique_locations = {}  # Changed to dictionary to store all numbers for each street
    for index, row in df.iterrows():
        try:
            # Clean and prepare values
            # Handle tax_code - convert to '0' if not a valid postal code
            tax_code = str(row[1]).strip() if pd.notna(row[1]) else '0'
            if tax_code == '':
                tax_code = '0'
            else:
                # Remove spaces and try to convert to int
                try:
                    int(tax_code.replace(' ', ''))
                except ValueError:
                    tax_code = '0'
                
            street_id = str(row[2]).strip() if pd.notna(row[2]) else ''  # odos_id (column C)
            street_name = re.sub(r'\s+', ' ', str(row[3])).strip() if pd.notna(row[3]) else ''  # onoma (column D)
            
            # Handle street_number - keep as string, convert empty or '-' to NULL
            street_number = str(row[4]).strip() if pd.notna(row[4]) else '0'
            if street_number == '-' or street_number == '':
                street_number = '0'
            street_number = f"'{street_number}'"  # Wrap in quotes for varchar
                
            area_id = int(float(row[5])) if pd.notna(row[5]) else 0  # dimotiko_diamerismo (column F)
            
            # Skip if essential fields are empty
            if not all([tax_code, street_id, street_name]):
                continue
                
            # Create key for uniqueness check (without street number)
            location_key = (tax_code, street_id, street_name)
            
            # Add to dictionary if it's a new street or update numbers if it exists
            if location_key not in unique_locations:
                unique_locations[location_key] = {
                    'area_id': area_id,
                    'numbers': set([street_number]) if street_number else set()
                }
            else:
                if street_number:
                    unique_locations[location_key]['numbers'].add(street_number)
        except Exception as e:
            print(f"Error processing row {index}: {e}")
            print(f"Row data: {row}")
            continue
    
    # Generate SQL commands for unique locations
    for (tax_code, street_id, street_name), data in unique_locations.items():
        # Create one entry without number if no numbers exist
        if not data['numbers']:
            sql = f"""
            INSERT INTO tree_db.location (
                tax_code, street_id, street_name, street_number, area_id
            )
            VALUES (
                '{tax_code}',
                '{street_id}',
                '{street_name}',
                NULL,
                {data['area_id']}
            );
            """
            sql_commands.append(sql)
        else:
            # Create one entry for each unique number
            for number in data['numbers']:
                sql = f"""
                INSERT INTO tree_db.location (
                    tax_code, street_id, street_name, street_number, area_id
                )
                VALUES (
                    '{tax_code}',
                    '{street_id}',
                    '{street_name}',
                    {number},
                    {data['area_id']}
                );
                """
                sql_commands.append(sql)
                
    print(f"Found {len(unique_locations)} unique streets with {sum(len(data['numbers']) for data in unique_locations.values())} total addresses")
    return sql_commands


def read_trees_data(excel_file):
    """Read trees data from Excel and generate SQL insert commands"""
    # Read Excel without headers
    df = pd.read_excel(excel_file, header=None)
    sql_commands = []
    
    # Skip header row
    df = df.iloc[1:]
    
    for index, row in df.iterrows():
        try:
            # Skip row if essential data is missing
            # if pd.isna(row[7]) or pd.isna(row[8]) or pd.isna(row[9]) or pd.isna(row[10]):  # lat/lon coordinates
            #     continue
                
            # Get location components for foreign key reference
            # Handle tax_code - convert to '0' if not a valid postal code
            tax_code = str(row[1]).strip() if pd.notna(row[1]) else '0'
            if tax_code == '':
                tax_code = '0'
            else:
                # Remove spaces and try to convert to int
                try:
                    int(tax_code.replace(' ', ''))
                except ValueError:
                    tax_code = '0'
                    
            street_id = str(row[2]).strip() if pd.notna(row[2]) else ''  # odos_id (column C)
            street_name = re.sub(r'\s+', ' ', str(row[3])).strip() if pd.notna(row[3]) else ''  # onoma (column D)
            
            # Handle street_number - keep as string, convert empty or '-' to NULL
            street_number = str(row[4]).strip() if pd.notna(row[4]) else '0'
            if street_number == '-' or street_number == '':
                street_number = '0'
            street_number = f"'{street_number}'"  # Wrap in quotes for varchar
            
            # Clean common_name
            common_name = re.sub(r'\s+', ' ', str(row[7])).strip() if pd.notna(row[7]) else ''
            
            sql = f"""
            SET @type_id = (
                SELECT COALESCE(
                    (SELECT tt.id FROM tree_db.tree_type tt WHERE tt.greek_name = '{common_name}' LIMIT 1),
                    (SELECT tt.id FROM tree_db.tree_type tt WHERE tt.greek_name = '_ΑΓΝΩΣΤΟ ΕΙΔΟΣ_' LIMIT 1)
                )
            );
            SET @location_id = (SELECT l.id FROM tree_db.location l WHERE l.tax_code = '{tax_code}' AND l.street_id = '{street_id}' AND l.street_name = '{street_name}' AND l.street_number = {street_number} LIMIT 1);
            INSERT INTO tree_db.tree (
                type_code, name, absolute_position_x, absolute_position_y,
                lat, lon, location_id
            )VALUES(
                @type_id,
                '{common_name}',
                {row[8]},
                {row[9]},
                {row[10]},
                {row[11]},
                @location_id
            );
            """
            sql_commands.append(sql)
            
        except Exception as e:
            print(f"Error processing row {index}: {e}")
            print(f"Row data: {row}")
            continue
    
    return sql_commands


def import_data_to_db(sql_commands):
    """Import data to MySQL database"""
    try:
        connection = mysql.connector.connect(
            host='localhost',
            user='root',  # replace with your MySQL username
            password='',  # replace with your MySQL password
            database='tree_db',
            allow_local_infile=True
        )
        
        cursor = connection.cursor()

        for command in sql_commands:
            # Split commands if they contain multiple statements
            for statement in command.split(';'):
                if statement.strip():  # Skip empty statements
                    cursor.execute(statement + ';')
            connection.commit()  # Commit after each complete command
            
        print("Data imported successfully!")
        
    except mysql.connector.Error as error:
        print(f"Failed to import data into MySQL table: {error}")
        
    finally:
        if connection.is_connected():
            cursor.close()
            connection.close()
            print("MySQL connection closed")

def delete_all_data():
    """Delete all data from all tables"""
    try:
        connection = mysql.connector.connect(
            host='localhost',
            user='root',    
            password='',
            database='tree_db'
        )
        
        cursor = connection.cursor()    
        
        # Delete all data from all tables
        for table in ['tree', 'location', 'tree_type', 'polution', 'meteo_station']:
            cursor.execute(f"DELETE FROM {table}")
        connection.commit()
        
        print("All data deleted successfully!")
    
    except mysql.connector.Error as error:
        print(f"Failed to delete data from MySQL table: {error}")
        
    finally:
        if connection.is_connected():
            cursor.close()
            connection.close()
            print("MySQL connection closed")
            

def export_sql_commands(sql_commands, filename='import_data.sql'):
    """Export SQL commands to a file"""
    with open(filename, 'w', encoding='utf-8') as file:
        for command in sql_commands:
            file.write(command.strip() + '\n')
    print(f"SQL commands exported to {filename}")


def main():
    polution_file = 'Polution.xlsx'
    copernicus_file1 = 'municipality_of_Thessaloniki_pollutants_conc_timeseries-yearly_2022.csv'
    copernicus_file2 = 'municipality_of_Thessaloniki_pollutants_conc_timeseries-yearly_2023.csv'
    copernicus_file3 = 'municipality_of_Thessaloniki_pollutants_conc_timeseries-yearly_2024.csv'
    trees_file = 'Trees.xlsx'
    tree_type_file = 'trees_thess_0_1_1.csv'
    
    # Read data and generate SQL commands
    meteo_stations_commands = read_meteo_stations_data(polution_file)
    pollution_commands = read_pollution_data(polution_file)
    pollution_copernicus_commands = read_pollution_copernicus_data(copernicus_file1)
    pollution_copernicus_commands2 = read_pollution_copernicus_data(copernicus_file2)
    pollution_copernicus_commands3 = read_pollution_copernicus_data(copernicus_file3)
    tree_types_commands = read_tree_types_data(tree_type_file)
    locations_commands = read_locations_data(trees_file)
    trees_commands = read_trees_data(trees_file)
    
    # Combine all SQL commands - note the order is important for foreign keys
    all_commands = (meteo_stations_commands + pollution_commands + 
                   tree_types_commands + locations_commands + trees_commands)
    
    # Export individual files
    export_sql_commands(meteo_stations_commands, 'insert_db/meteo_stations_data.sql')
    export_sql_commands(pollution_commands, 'insert_db/pollution_data.sql')
    #Copernicus pollution data
    export_sql_commands(pollution_copernicus_commands, 'insert_db/pollution_copernicus_data.sql')
    export_sql_commands(pollution_copernicus_commands2, 'insert_db/pollution_copernicus_data2.sql')
    export_sql_commands(pollution_copernicus_commands3, 'insert_db/pollution_copernicus_data3.sql')

    export_sql_commands(tree_types_commands, 'insert_db/tree_types_data.sql')
    export_sql_commands(locations_commands, 'insert_db/locations_data.sql')
    export_sql_commands(trees_commands, 'insert_db/trees_data.sql')
    # export_sql_commands(all_commands, 'import_data.sql')

    #import data to db
    import_data_to_db(meteo_stations_commands)
    print("meteo_stations_commands imported")
    import_data_to_db(pollution_commands)
    print("pollution_commands imported")
    import_data_to_db(pollution_copernicus_commands)
    import_data_to_db(pollution_copernicus_commands2)
    import_data_to_db(pollution_copernicus_commands3)
    print("pollution_copernicus_commands imported")
    import_data_to_db(tree_types_commands)  
    print("tree_types_commands imported")
    import_data_to_db(locations_commands)
    print("locations_commands imported")
    import_data_to_db(trees_commands)
    print("trees_commands imported")



if __name__ == "__main__":
    main()

